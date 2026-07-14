<?php

namespace App\Repositories;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReservationRepository
{
    /**
     * 同一サロン・同一スタッフの予約書き込みを直列化する。
     * 空き時間帯には行ロック対象が存在せず同時INSERTを防げないため、
     * advisory lock を使う。トランザクション内で呼ぶこと（終了時に自動解放）。
     */
    public function lockForBooking(int $salonId, int $userId): void
    {
        DB::select(
            'select pg_advisory_xact_lock(hashtextextended(?, 0))',
            ["reservation:{$salonId}:{$userId}"],
        );
    }

    public function listBetween(int $salonId, Carbon $from, Carbon $toExclusive, array $filters): Collection
    {
        return Reservation::where('salon_id', $salonId)
            ->where('start_at', '>=', $from)
            ->where('start_at', '<', $toExclusive)
            ->when(isset($filters['user_id']), fn ($query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('status', $filters['status']))
            ->with(['customer', 'menu', 'user'])
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();
    }

    public function findOrFail(int $salonId, int $id): Reservation
    {
        return Reservation::where('salon_id', $salonId)
            ->with(['customer', 'menu', 'user'])
            ->findOrFail($id);
    }

    /**
     * 時間帯 [startAt, endAt) が重なる reserved / visited の予約を行ロック付きで取得する。
     */
    public function findOverlappingForUpdate(
        int $salonId,
        int $userId,
        Carbon $startAt,
        Carbon $endAt,
        ?int $excludeId = null,
    ): Collection {
        return Reservation::where('salon_id', $salonId)
            ->where('user_id', $userId)
            ->whereIn('status', [ReservationStatus::Reserved->value, ReservationStatus::Visited->value])
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->when($excludeId !== null, fn ($query) => $query->whereKeyNot($excludeId))
            ->lockForUpdate()
            ->get();
    }

    public function create(int $salonId, array $data): Reservation
    {
        $reservation = Reservation::create(array_merge($data, [
            'salon_id' => $salonId,
        ]));

        return $reservation->load(['customer', 'menu', 'user']);
    }

    public function update(Reservation $reservation, array $data): Reservation
    {
        $reservation->update($data);

        return $reservation->fresh(['customer', 'menu', 'user']);
    }

    public function delete(Reservation $reservation): void
    {
        $reservation->delete();
    }
}
