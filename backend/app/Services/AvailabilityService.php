<?php

namespace App\Services;

use App\Models\GoogleBusyBlock;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Repositories\GoogleBusyBlockRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\UserRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 公開Web予約の空き枠計算（booking.md Business Rules 1・2）。
 * 予約作成時のサーバ側枠検証も同一ロジック（bookableSlots）を通す。
 */
class AvailabilityService
{
    private const SLOT_INTERVAL_MINUTES = 30;

    private const MIN_LEAD_MINUTES = 30;

    private const MAX_ADVANCE_DAYS = 60;

    public function __construct(
        private readonly BusinessHourService $businessHourService,
        private readonly ReservationRepository $reservationRepository,
        private readonly UserRepository $userRepository,
        private readonly GoogleBusyBlockRepository $busyBlockRepository,
    ) {}

    /**
     * 指定日の空き枠を start_at 昇順で返す。
     * userId 省略時は「指名なし」＝有効スタッフの誰か1人でも空いていれば空き枠とする。
     *
     * @return Collection<int, Carbon>
     */
    public function listSlots(Salon $salon, Menu $menu, string $date, ?int $userId = null): Collection
    {
        $slots = $this->bookableSlots($salon, $menu, $date);

        if ($slots->isEmpty()) {
            return $slots;
        }

        $staffIds = $userId !== null
            ? [$userId]
            : $this->userRepository->listActiveBySalon($salon->id)->pluck('id')->all();

        if ($staffIds === []) {
            return collect();
        }

        $rangeStart = $slots->first()->copy()->utc();
        $rangeEnd = $slots->last()->copy()->addMinutes($menu->duration_minutes)->utc();

        $reservations = $this->reservationRepository->listOverlapping(
            $salon->id,
            $staffIds,
            $rangeStart,
            $rangeEnd,
        );

        // 外部予定（busy）と重なる枠は予約不可（shared 接続の busy は user_id=null で全スタッフを塞ぐ）
        $busyBlocks = $this->busyBlockRepository->listBySalonBetween($salon->id, $rangeStart, $rangeEnd);

        return $slots
            ->filter(function (Carbon $slot) use ($staffIds, $reservations, $busyBlocks, $menu) {
                $endAt = $slot->copy()->addMinutes($menu->duration_minutes);

                return collect($staffIds)->contains(
                    fn (int $staffId) => ! $this->hasOverlap($reservations, $staffId, $slot, $endAt)
                        && ! $this->hasBusyOverlap($busyBlocks, $staffId, $slot, $endAt),
                );
            })
            ->values();
    }

    /**
     * start_at が空き枠計算と同一の枠条件（営業時間・30分グリッド・予約可能範囲）を満たすか。
     * 重複予約の有無は advisory lock 配下で別途判定する。
     */
    public function isBookableSlot(Salon $salon, Menu $menu, Carbon $startAt): bool
    {
        $localDate = $startAt->copy()->setTimezone(config('app.salon_timezone'))->format('Y-m-d');

        return $this->bookableSlots($salon, $menu, $localDate)
            ->contains(fn (Carbon $slot) => $slot->eq($startAt));
    }

    /**
     * 営業時間・30分グリッド・予約可能範囲を満たす枠（重複予約は考慮しない）。
     *
     * @return Collection<int, Carbon>
     */
    private function bookableSlots(Salon $salon, Menu $menu, string $date): Collection
    {
        $timezone = config('app.salon_timezone');
        $day = Carbon::createFromFormat('!Y-m-d', $date, $timezone);
        $today = Carbon::today($timezone);

        if ($day->lt($today) || $day->gt($today->copy()->addDays(self::MAX_ADVANCE_DAYS))) {
            return collect();
        }

        $businessHour = $this->businessHourService->list($salon->id)
            ->firstWhere('day_of_week', $day->dayOfWeek);

        if ($businessHour === null || $businessHour->is_closed) {
            return collect();
        }

        $closeAt = $this->timeOn($day, $businessHour->close_time);
        $earliestStart = Carbon::now($timezone)->addMinutes(self::MIN_LEAD_MINUTES);

        $slots = collect();

        for (
            $slot = $this->timeOn($day, $businessHour->open_time);
            $slot->copy()->addMinutes($menu->duration_minutes)->lte($closeAt);
            $slot->addMinutes(self::SLOT_INTERVAL_MINUTES)
        ) {
            if ($slot->gte($earliestStart)) {
                $slots->push($slot->copy());
            }
        }

        return $slots;
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     */
    private function hasOverlap(Collection $reservations, int $userId, Carbon $startAt, Carbon $endAt): bool
    {
        return $reservations->contains(
            fn (Reservation $reservation) => $reservation->user_id === $userId
                && $reservation->start_at->lt($endAt)
                && $reservation->end_at->gt($startAt),
        );
    }

    /**
     * @param  Collection<int, GoogleBusyBlock>  $busyBlocks
     */
    private function hasBusyOverlap(Collection $busyBlocks, int $userId, Carbon $startAt, Carbon $endAt): bool
    {
        return $busyBlocks->contains(
            fn (GoogleBusyBlock $busy) => ($busy->user_id === null || $busy->user_id === $userId)
                && $busy->start_at->lt($endAt)
                && $busy->end_at->gt($startAt),
        );
    }

    private function timeOn(Carbon $day, string $time): Carbon
    {
        [$hour, $minute] = explode(':', $time);

        return $day->copy()->setTime((int) $hour, (int) $minute);
    }
}
