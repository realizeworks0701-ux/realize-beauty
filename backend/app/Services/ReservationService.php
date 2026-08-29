<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Jobs\SyncReservationToGoogleJob;
use App\Models\Menu;
use App\Models\Reservation;
use App\Repositories\CustomerRepository;
use App\Repositories\MenuRepository;
use App\Repositories\ReservationRepository;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReservationService
{
    private const MAX_PERIOD_DAYS = 31;

    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly MenuRepository $menuRepository,
        private readonly UserRepository $userRepository,
    ) {}

    /**
     * from / to はサロンのタイムゾーン（JST）の日付境界 [from 00:00, to 24:00) で解釈する。
     */
    public function list(int $salonId, array $filters): Collection
    {
        $timezone = config('app.salon_timezone');

        $from = isset($filters['from'])
            ? Carbon::createFromFormat('!Y-m-d', $filters['from'], $timezone)
            : Carbon::today($timezone);

        $to = isset($filters['to'])
            ? Carbon::createFromFormat('!Y-m-d', $filters['to'], $timezone)
            : $from->copy();

        if ($to->lt($from)) {
            throw ValidationException::withMessages([
                'to' => ['to には from 以降の日付を指定してください。'],
            ]);
        }

        if ($from->diffInDays($to) >= self::MAX_PERIOD_DAYS) {
            throw ValidationException::withMessages([
                'to' => ['期間は最大31日以内で指定してください。'],
            ]);
        }

        return $this->reservationRepository->listBetween(
            $salonId,
            $from->copy()->utc(),
            $to->copy()->addDay()->utc(),
            $filters,
        );
    }

    public function find(int $salonId, int $id): Reservation
    {
        return $this->reservationRepository->findOrFail($salonId, $id);
    }

    public function create(int $salonId, array $data): Reservation
    {
        $this->assertCustomerExists($salonId, (int) $data['customer_id']);
        $this->assertActiveStaffExists($salonId, (int) $data['user_id']);
        $menu = $this->findActiveMenuOrFail($salonId, (int) $data['menu_id']);

        $startAt = Carbon::parse($data['start_at'])->utc();
        $endAt = $startAt->copy()->addMinutes($menu->duration_minutes);

        $reservation = DB::transaction(function () use ($salonId, $data, $menu, $startAt, $endAt) {
            $this->assertNoDoubleBooking($salonId, (int) $data['user_id'], $startAt, $endAt);

            return $this->reservationRepository->create($salonId, [
                'customer_id' => $data['customer_id'],
                'menu_id' => $data['menu_id'],
                'user_id' => $data['user_id'],
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => ReservationStatus::Reserved,
                'price' => $menu->price,
                'note' => $data['note'] ?? null,
            ]);
        });

        $this->dispatchGoogleSync($reservation);

        return $reservation;
    }

    public function update(int $salonId, int $id, array $data): Reservation
    {
        $reservation = $this->reservationRepository->findOrFail($salonId, $id);
        $previousUserId = $reservation->user_id;
        $previousCustomerId = $reservation->customer_id;

        if (array_key_exists('customer_id', $data)) {
            $this->assertCustomerExists($salonId, (int) $data['customer_id']);
        }

        if (array_key_exists('user_id', $data)) {
            $this->assertActiveStaffExists($salonId, (int) $data['user_id']);
        }

        $menuChanged = array_key_exists('menu_id', $data) && (int) $data['menu_id'] !== $reservation->menu_id;
        $menu = $menuChanged
            ? $this->findActiveMenuOrFail($salonId, (int) $data['menu_id'])
            : $reservation->menu;

        $startChanged = array_key_exists('start_at', $data);
        $startAt = $startChanged
            ? Carbon::parse($data['start_at'])->utc()
            : $reservation->start_at->copy()->utc();

        $endAt = ($startChanged || $menuChanged)
            ? $startAt->copy()->addMinutes($menu->duration_minutes)
            : $reservation->end_at->copy()->utc();

        $userId = (int) ($data['user_id'] ?? $reservation->user_id);
        $status = isset($data['status'])
            ? ReservationStatus::from($data['status'])
            : $reservation->status;

        $attributes = array_merge(
            Arr::only($data, ['customer_id', 'menu_id', 'user_id', 'status', 'note']),
            ['start_at' => $startAt, 'end_at' => $endAt],
            $menuChanged ? ['price' => $menu->price] : [],
        );

        $updated = DB::transaction(function () use ($salonId, $reservation, $attributes, $userId, $startAt, $endAt, $status, $previousCustomerId) {
            if (in_array($status, [ReservationStatus::Reserved, ReservationStatus::Visited], true)) {
                $this->assertNoDoubleBooking($salonId, $userId, $startAt, $endAt, $reservation->id);
            }

            $updated = $this->reservationRepository->update($reservation, $attributes);

            foreach (array_unique([$previousCustomerId, $updated->customer_id]) as $customerId) {
                $this->refreshVisitDates($salonId, $customerId);
            }

            return $updated;
        });

        // 担当変更時は変更前スタッフIDを渡し、旧接続のイベント削除→新接続で作成し直させる
        $this->dispatchGoogleSync($updated, $previousUserId);

        return $updated;
    }

    public function delete(int $salonId, int $id): void
    {
        $reservation = $this->reservationRepository->findOrFail($salonId, $id);

        DB::transaction(function () use ($salonId, $reservation) {
            $this->reservationRepository->delete($reservation);
            $this->refreshVisitDates($salonId, $reservation->customer_id);
        });

        // 論理削除（誤登録の取り消し）でも Google イベントを削除する（孤児イベントを残さない）
        $this->dispatchGoogleSync($reservation);
    }

    /**
     * 顧客の来店日を status=visited の予約から引き直す。
     * 条件分岐を持たせず更新・削除で常に引き直すことで、visited の取り消し・
     * 予約削除・顧客の付け替えのいずれでも値が自己修復する。
     */
    private function refreshVisitDates(int $salonId, int $customerId): void
    {
        $timezone = config('app.salon_timezone');
        $range = $this->reservationRepository->visitDateRange($salonId, $customerId);

        $this->customerRepository->updateVisitDates(
            $salonId,
            $customerId,
            $range['first']?->copy()->setTimezone($timezone)->toDateString(),
            $range['last']?->copy()->setTimezone($timezone)->toDateString(),
        );
    }

    /**
     * 送信同期ジョブを投入する。Google 連携未設定（mode = null）のサロンでは投入しない。
     */
    private function dispatchGoogleSync(Reservation $reservation, ?int $previousUserId = null): void
    {
        if ($reservation->salon->google_calendar_mode === null) {
            return;
        }

        SyncReservationToGoogleJob::dispatch($reservation->id, $previousUserId)->afterCommit();
    }

    private function assertCustomerExists(int $salonId, int $customerId): void
    {
        if ($this->customerRepository->find($salonId, $customerId) === null) {
            throw ValidationException::withMessages([
                'customer_id' => ['指定した顧客が見つかりません。'],
            ]);
        }
    }

    private function assertActiveStaffExists(int $salonId, int $userId): void
    {
        if ($this->userRepository->findActiveBySalon($salonId, $userId) === null) {
            throw ValidationException::withMessages([
                'user_id' => ['指定したスタッフが見つかりません。'],
            ]);
        }
    }

    private function findActiveMenuOrFail(int $salonId, int $menuId): Menu
    {
        $menu = $this->menuRepository->findActive($salonId, $menuId);

        if ($menu === null) {
            throw ValidationException::withMessages([
                'menu_id' => ['指定したメニューは利用できません。'],
            ]);
        }

        return $menu;
    }

    private function assertNoDoubleBooking(
        int $salonId,
        int $userId,
        Carbon $startAt,
        Carbon $endAt,
        ?int $excludeId = null,
    ): void {
        $this->reservationRepository->lockForBooking($salonId, $userId);

        $overlapping = $this->reservationRepository->findOverlappingForUpdate(
            $salonId,
            $userId,
            $startAt,
            $endAt,
            $excludeId,
        );

        if ($overlapping->isNotEmpty()) {
            throw ValidationException::withMessages([
                'start_at' => ['指定した時間帯は既に予約が入っています。'],
            ]);
        }
    }
}
