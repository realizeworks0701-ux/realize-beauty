<?php

namespace App\Repositories;

use App\Enums\ReservationStatus;
use App\Models\GoogleCalendarConnection;
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

    /**
     * 同一サロン・同一 phone（正規化後）のWeb予約書き込みを直列化する。
     * 上限チェック〜顧客作成の間の競合（上限バイパス・重複顧客作成）を防ぐ。
     * トランザクション内で呼ぶこと（終了時に自動解放）。
     */
    public function lockForPhoneBooking(int $salonId, string $normalizedPhone): void
    {
        DB::select(
            'select pg_advisory_xact_lock(hashtextextended(?, 0))',
            ["booking-phone:{$salonId}:{$normalizedPhone}"],
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

    /**
     * 空き枠計算用。時間帯 [from, toExclusive) に重なる reserved / visited の予約を
     * 対象スタッフ分まとめて取得する（ロックなし）。
     *
     * @param  array<int, int>  $userIds
     */
    public function listOverlapping(int $salonId, array $userIds, Carbon $from, Carbon $toExclusive): Collection
    {
        return Reservation::where('salon_id', $salonId)
            ->whereIn('user_id', $userIds)
            ->whereIn('status', [ReservationStatus::Reserved->value, ReservationStatus::Visited->value])
            ->where('start_at', '<', $toExclusive)
            ->where('end_at', '>', $from)
            ->get();
    }

    /**
     * 同一サロン内で同一 phone（正規化後）の未来の reserved 予約件数（虚偽予約の緩和）。
     */
    public function countFutureReservedByNormalizedPhone(int $salonId, string $normalizedPhone, Carbon $from): int
    {
        return Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Reserved->value)
            ->where('start_at', '>', $from)
            ->whereHas('customer', fn ($query) => $query->whereNormalizedPhone($normalizedPhone))
            ->count();
    }

    /**
     * 公開キャンセルページ用。所属サロンが無効なら 404 とするため is_active を条件に含める。
     */
    public function findByBookingTokenOrFail(string $bookingToken): Reservation
    {
        return Reservation::where('booking_token', $bookingToken)
            ->whereHas('salon', fn ($query) => $query->where('is_active', true))
            ->with(['salon', 'menu', 'user'])
            ->firstOrFail();
    }

    /**
     * 顧客キャンセルの条件付き UPDATE（サロン側 PATCH と同時実行しても一貫性を保つ）。
     * 更新件数0はキャンセル済み・来店済み・開始時刻超過のいずれか。
     */
    public function cancelByBookingToken(string $bookingToken, Carbon $now): int
    {
        return Reservation::where('booking_token', $bookingToken)
            ->where('status', ReservationStatus::Reserved->value)
            ->where('start_at', '>', $now)
            ->update(['status' => ReservationStatus::Cancelled->value]);
    }

    /**
     * 前日リマインダー対象（reserved・LINE連携済み顧客・未送信・サロンのLINE連携が有効）を取得する。
     */
    public function listForReminder(Carbon $from, Carbon $toExclusive): Collection
    {
        return Reservation::where('status', ReservationStatus::Reserved->value)
            ->whereNull('reminder_sent_at')
            ->where('start_at', '>=', $from)
            ->where('start_at', '<', $toExclusive)
            ->whereHas('customer', fn ($query) => $query->whereNotNull('line_user_id'))
            ->whereHas('salon.lineSetting', fn ($query) => $query->where('is_active', true))
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * LINE通知ジョブ用に関連込みで再取得する。
     */
    public function findWithLineContext(int $id): ?Reservation
    {
        return Reservation::with(['customer', 'menu', 'salon.lineSetting'])->find($id);
    }

    public function markReminderSent(Reservation $reservation): void
    {
        $reservation->update(['reminder_sent_at' => now()]);
    }

    /**
     * 送信同期ジョブ用の再読み込み（実行時点の最新状態を書くため）。
     * 論理削除された予約（誤登録取り消し）も対象イベント削除のため withTrashed で取得する。
     */
    public function findForSync(int $id): ?Reservation
    {
        return Reservation::withTrashed()
            ->with(['menu', 'user', 'salon'])
            ->find($id);
    }

    /**
     * 受信同期の RB 由来判定。テナント境界は接続レコードの (salon_id, google_event_id) 突合で確定する
     * （per_staff は担当 user_id の一致も条件に含める）。マーカーは突合条件に含めない（改竄可能なため）。
     */
    public function findRbDerived(GoogleCalendarConnection $connection, string $googleEventId): ?Reservation
    {
        return Reservation::where('salon_id', $connection->salon_id)
            ->where('google_event_id', $googleEventId)
            ->when($connection->user_id !== null, fn ($query) => $query->where('user_id', $connection->user_id))
            ->with('menu')
            ->first();
    }

    /**
     * 同期起因の属性更新（google_event_id・status・start_at/end_at）。関連の再読込は行わない。
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateForSync(Reservation $reservation, array $attributes): void
    {
        $reservation->update($attributes);
    }

    /**
     * 初回送信同期の対象（同期窓内 [from, to) の status=reserved な対象予約）。
     * per_staff は当該スタッフ担当、shared（$userId = null）は全スタッフ。
     *
     * @return Collection<int, Reservation>
     */
    public function listReservedForGoogleSync(int $salonId, ?int $userId, Carbon $from, Carbon $to): Collection
    {
        return Reservation::where('salon_id', $salonId)
            ->where('status', ReservationStatus::Reserved->value)
            ->where('start_at', '>=', $from)
            ->where('start_at', '<', $to)
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('start_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * 接続解除・モード切替時に対象範囲の google_event_id を null クリアする
     * （イベントIDはカレンダー単位のスコープ。接続が消えた時点で参照は無効になる）。
     * 論理削除済みの予約も対象に含める（旧イベントの孤児参照を残さない）。
     */
    public function clearGoogleEventIdForScope(int $salonId, ?int $userId): int
    {
        return Reservation::withTrashed()
            ->where('salon_id', $salonId)
            ->whereNotNull('google_event_id')
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->update(['google_event_id' => null]);
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
