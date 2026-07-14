<?php

namespace App\Services;

use App\Enums\ReservationStatus;
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

        return DB::transaction(function () use ($salonId, $data, $startAt, $endAt) {
            $this->assertNoDoubleBooking($salonId, (int) $data['user_id'], $startAt, $endAt);

            return $this->reservationRepository->create($salonId, [
                'customer_id' => $data['customer_id'],
                'menu_id' => $data['menu_id'],
                'user_id' => $data['user_id'],
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => ReservationStatus::Reserved,
                'note' => $data['note'] ?? null,
            ]);
        });
    }

    public function update(int $salonId, int $id, array $data): Reservation
    {
        $reservation = $this->reservationRepository->findOrFail($salonId, $id);

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
        );

        return DB::transaction(function () use ($salonId, $reservation, $attributes, $userId, $startAt, $endAt, $status) {
            if (in_array($status, [ReservationStatus::Reserved, ReservationStatus::Visited], true)) {
                $this->assertNoDoubleBooking($salonId, $userId, $startAt, $endAt, $reservation->id);
            }

            return $this->reservationRepository->update($reservation, $attributes);
        });
    }

    public function delete(int $salonId, int $id): void
    {
        $reservation = $this->reservationRepository->findOrFail($salonId, $id);
        $this->reservationRepository->delete($reservation);
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
