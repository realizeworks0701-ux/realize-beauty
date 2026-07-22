<?php

namespace App\Services\Google;

use App\Enums\ReservationStatus;
use App\Models\GoogleCalendarConnection;
use App\Models\Reservation;
use App\Repositories\GoogleBusyBlockRepository;
use App\Repositories\GoogleCalendarConnectionRepository;
use App\Repositories\ReservationRepository;
use App\Services\BusinessHourService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 受信同期（Google → RB）のビジネスロジック。
 * syncToken 増分同期を基本とし、410 Gone・未保存時は同期窓の全同期にフォールバックする。
 * RB 由来判定はサーバ側突合（(salon_id, google_event_id) + per_staff の user_id）で行い、
 * マーカー（extendedProperties）は権威にしない（テナント境界。ADR-025 §4）。
 */
class GoogleCalendarSyncService
{
    /** 同期窓 = salon_timezone の「現在 〜 本日+61日 00:00（本日+60日の終日終端）」 */
    private const SYNC_WINDOW_DAYS = 61;

    /** busy から除外する eventType（primary に流れる特殊イベント。取り込むと丸1日塞がる） */
    private const EXCLUDED_EVENT_TYPES = ['workingLocation', 'birthday'];

    public function __construct(
        private readonly GoogleClient $client,
        private readonly GoogleTokenService $tokens,
        private readonly GoogleCalendarConnectionRepository $connections,
        private readonly GoogleBusyBlockRepository $busyBlocks,
        private readonly ReservationRepository $reservations,
        private readonly BusinessHourService $businessHours,
        private readonly GoogleEventPayloadBuilder $payloadBuilder,
    ) {}

    public function sync(GoogleCalendarConnection $connection): void
    {
        if ($connection->sync_token !== null) {
            try {
                $this->incrementalSync($connection);

                return;
            } catch (GoogleSyncTokenExpiredException) {
                // 410 → 保存済み syncToken を捨てて全同期し直す
                $this->connections->clearSyncToken($connection);
                $connection->sync_token = null;
            }
        }

        $this->fullSync($connection);
    }

    /**
     * 増分同期: syncToken のみを渡す（timeMin/timeMax とは併用不可）。singleEvents は付けてよい。
     */
    private function incrementalSync(GoogleCalendarConnection $connection): void
    {
        $window = $this->syncWindow();
        // CAS 用に同期開始時点の sync_token を控える（この増分同期が使ったトークン）
        $expectedSyncToken = $connection->sync_token;

        [$events, $nextSyncToken] = $this->fetchAllPages($connection, [
            'syncToken' => $connection->sync_token,
            'singleEvents' => 'true',
        ]);

        DB::transaction(function () use ($connection, $events, $window) {
            foreach ($events as $event) {
                $this->applyEvent($connection, $event, $window);
            }
        });

        $this->connections->updateSyncTokenIfMatches($connection->id, $expectedSyncToken, $nextSyncToken);
    }

    /**
     * 全同期: syncToken 無し + timeMin/timeMax + singleEvents。照合削除（reconcile）を伴う。
     */
    private function fullSync(GoogleCalendarConnection $connection): void
    {
        $window = $this->syncWindow();
        // 全同期は sync_token = null に対して走る（新規接続 or 410 後のクリア）。それを CAS 期待値にする
        $expectedSyncToken = $connection->sync_token;

        [$events, $nextSyncToken] = $this->fetchAllPages($connection, [
            'timeMin' => $window['from']->toRfc3339String(),
            'timeMax' => $window['to']->toRfc3339String(),
            'singleEvents' => 'true',
        ]);

        DB::transaction(function () use ($connection, $events, $window) {
            $seenBusyEventIds = [];

            foreach ($events as $event) {
                $busyEventId = $this->applyEvent($connection, $event, $window);

                if ($busyEventId !== null) {
                    $seenBusyEventIds[] = $busyEventId;
                }
            }

            // 全同期の応答には削除イベントが含まれないため、応答に現れなかった同期窓内の busy を刈り取る
            $existing = $this->busyBlocks->listEventIdsBetween($connection->id, $window['from'], $window['to']);
            $this->busyBlocks->deleteByEventIds($connection->id, array_values(array_diff($existing, $seenBusyEventIds)));
            // 窓外へ出た busy（幽霊 busy）も削除する
            $this->busyBlocks->deleteOutsideWindow($connection->id, $window['from'], $window['to']);
        });

        $this->connections->updateSyncTokenIfMatches($connection->id, $expectedSyncToken, $nextSyncToken);
    }

    /**
     * 全ページを辿って結合する（nextSyncToken は最終ページにのみ返る）。
     * 同一パラメータ + pageToken で辿り、全ページの結合結果のみを返す。
     * 各ページ取得は runWithAuthRetry 経由で 401 時に refresh + 1回再試行する
     * （410〈GoogleSyncTokenExpiredException〉はそのまま伝播し、呼び出し元が全同期へフォールバックする）。
     *
     * @param  array<string, mixed>  $baseParams
     * @return array{0: array<int, array<string, mixed>>, 1: string|null}
     */
    private function fetchAllPages(GoogleCalendarConnection $connection, array $baseParams): array
    {
        $events = [];
        $syncToken = null;
        $pageToken = null;

        do {
            $params = $pageToken === null ? $baseParams : array_merge($baseParams, ['pageToken' => $pageToken]);
            $body = $this->tokens->runWithAuthRetry(
                $connection,
                fn (string $token) => $this->client->listEvents($token, $connection->calendar_id, $params),
            );

            foreach ($body['items'] ?? [] as $item) {
                $events[] = $item;
            }

            $pageToken = $body['nextPageToken'] ?? null;

            if (isset($body['nextSyncToken'])) {
                $syncToken = $body['nextSyncToken'];
            }
        } while (is_string($pageToken) && $pageToken !== '');

        return [$events, $syncToken];
    }

    /**
     * 1イベントを適用する。busy として upsert した場合はその event_id を返す（照合削除の突合用）。
     *
     * @param  array<string, mixed>  $event
     * @param  array{from: Carbon, to: Carbon}  $window
     */
    private function applyEvent(GoogleCalendarConnection $connection, array $event, array $window): ?string
    {
        $eventId = $event['id'] ?? null;

        if (! is_string($eventId)) {
            return null;
        }

        // 1. 削除（tombstone）: id 以外は返る保証が無いため本文を見ず逆引きで分岐する
        if (($event['status'] ?? null) === 'cancelled') {
            $this->applyTombstone($connection, $eventId);

            return null;
        }

        // 2. RB 由来か（サーバ側突合。マーカーは条件に含めない）
        $reservation = $this->reservations->findRbDerived($connection, $eventId);

        if ($reservation !== null) {
            $this->applyRbDerived($connection, $reservation, $event);

            return null;
        }

        // 3. 外部予定（busy）。突合しないマーカー付きイベントもここで busy になる
        return $this->applyExternal($connection, $event, $eventId, $window);
    }

    private function applyTombstone(GoogleCalendarConnection $connection, string $eventId): void
    {
        $reservation = $this->reservations->findRbDerived($connection, $eventId);

        if ($reservation !== null) {
            // Google 側削除 → 予約キャンセル。ただし cancelled / no_show は自らの削除のエコーなので no-op
            // （no_show を cancelled に潰さない。業務データの保護）
            if (in_array($reservation->status, [ReservationStatus::Reserved, ReservationStatus::Visited], true)) {
                $this->reservations->updateForSync($reservation, ['status' => ReservationStatus::Cancelled]);
            }

            return;
        }

        $this->busyBlocks->deleteByEventIds($connection->id, [$eventId]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @param  array{from: Carbon, to: Carbon}  $window
     */
    private function applyExternal(GoogleCalendarConnection $connection, array $event, string $eventId, array $window): ?string
    {
        // 除外3種は取り込まない + 既存 busy を消す（後から transparent 化・辞退した場合の幽霊 busy を残さない）
        if ($this->isExcludedFromBusy($event)) {
            $this->busyBlocks->deleteByEventIds($connection->id, [$eventId]);

            return null;
        }

        [$startAt, $endAt] = $this->extractInterval($event);

        if ($startAt === null || $endAt === null) {
            return null;
        }

        // 同期窓と重ならない外部予定は取り込まず、既存があれば削除する
        if ($endAt->lte($window['from']) || $startAt->gte($window['to'])) {
            $this->busyBlocks->deleteByEventIds($connection->id, [$eventId]);

            return null;
        }

        $this->busyBlocks->upsertByEventId($connection, $eventId, $startAt, $endAt);

        return $eventId;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function applyRbDerived(GoogleCalendarConnection $connection, Reservation $reservation, array $event): void
    {
        [$eventStart, $eventEnd] = $this->extractInterval($event);

        if ($eventStart === null || $eventEnd === null) {
            return;
        }

        $rbStart = $reservation->start_at->copy()->utc();
        $rbEnd = $reservation->end_at->copy()->utc();

        // no-op 条件は start と end の両方が一致すること
        if ($eventStart->eq($rbStart) && $eventEnd->eq($rbEnd)) {
            return;
        }

        // staleness ガード: RB の方が新しければ no-op（送信同期ジョブが後で正しい値を書く）
        if ($this->rbIsNewer($reservation, $event)) {
            return;
        }

        // start が変わった → 反映（競合なら巻き戻し）。end は常に start + menu.duration で再導出する
        if (! $eventStart->eq($rbStart)) {
            $newStart = $eventStart;
            $newEnd = $newStart->copy()->addMinutes($reservation->menu->duration_minutes);

            if ($this->hasConflict($reservation, $newStart, $newEnd)) {
                $this->rollbackToGoogle($connection, $reservation);

                return;
            }

            // 受信起因の予約更新では送信同期を投入しない（無駄な書き戻しの往復を避ける）
            $this->reservations->updateForSync($reservation, ['start_at' => $newStart, 'end_at' => $newEnd]);

            // Google 側の end が導出値と乖離していれば、start が動いていても RB の値で巻き戻す（ADR-025 §6）。
            // updateForSync でモデルは新 start + 導出 end を保持済みなので、それがそのまま書き戻される。
            if (! $eventEnd->eq($newEnd)) {
                $this->rollbackToGoogle($connection, $reservation);
            }

            return;
        }

        // start 一致・end 不一致 = Google 上で長さだけを変えられた → RB の値で巻き戻す
        $this->rollbackToGoogle($connection, $reservation);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function rbIsNewer(Reservation $reservation, array $event): bool
    {
        if (! isset($event['updated']) || ! is_string($event['updated'])) {
            return false;
        }

        // event.updated（RFC3339・Z）と reservation.updated_at を UTC の instant として比較する
        $eventUpdated = Carbon::parse($event['updated'])->utc();
        $leeway = (int) config('services.google.sync_leeway_seconds', 10);
        $threshold = $reservation->updated_at->copy()->utc()->addSeconds($leeway);

        return $eventUpdated->lte($threshold);
    }

    /**
     * 受信同期が明示的に events.update する唯一の経路（RB を真実として Google 側を戻す）。
     */
    private function rollbackToGoogle(GoogleCalendarConnection $connection, Reservation $reservation): void
    {
        if ($reservation->google_event_id === null) {
            return;
        }

        $reservation->loadMissing('user');
        $payload = $this->payloadBuilder->build($connection, $reservation);

        $this->tokens->runWithAuthRetry(
            $connection,
            fn (string $token) => $this->client->updateEvent($token, $connection->calendar_id, $reservation->google_event_id, $payload),
        );
    }

    private function hasConflict(Reservation $reservation, Carbon $start, Carbon $end): bool
    {
        // 他予約（同一スタッフ・reserved/visited・自分を除く）
        $conflictingReservation = $this->reservations
            ->listOverlapping($reservation->salon_id, [$reservation->user_id], $start, $end)
            ->contains(fn (Reservation $other) => $other->id !== $reservation->id);

        if ($conflictingReservation) {
            return true;
        }

        // busy（当該スタッフ + shared のサロン全体）
        if ($this->busyBlocks->listOverlapping($reservation->salon_id, $reservation->user_id, $start, $end)->isNotEmpty()) {
            return true;
        }

        // 営業時間
        return ! $this->withinBusinessHours($reservation->salon_id, $start, $end);
    }

    private function withinBusinessHours(int $salonId, Carbon $start, Carbon $end): bool
    {
        $timezone = config('app.salon_timezone');
        $localStart = $start->copy()->setTimezone($timezone);
        $localEnd = $end->copy()->setTimezone($timezone);

        // 日をまたぐ移動は営業時間内に収まらない（end は排他とみなし1秒戻して同日判定する）
        if ($localStart->toDateString() !== $localEnd->copy()->subSecond()->toDateString()) {
            return false;
        }

        $businessHour = $this->businessHours->list($salonId)->firstWhere('day_of_week', $localStart->dayOfWeek);

        if ($businessHour === null || $businessHour->is_closed) {
            return false;
        }

        [$openHour, $openMinute] = explode(':', $businessHour->open_time);
        [$closeHour, $closeMinute] = explode(':', $businessHour->close_time);
        $open = $localStart->copy()->setTime((int) $openHour, (int) $openMinute);
        $close = $localStart->copy()->setTime((int) $closeHour, (int) $closeMinute);

        return $localStart->gte($open) && $localEnd->lte($close);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function isExcludedFromBusy(array $event): bool
    {
        if (($event['transparency'] ?? null) === 'transparent') {
            return true;
        }

        if (in_array($event['eventType'] ?? null, self::EXCLUDED_EVENT_TYPES, true)) {
            return true;
        }

        // 接続アカウント本人が辞退した会議（辞退済みでも transparency は opaque のまま残るため）
        foreach ($event['attendees'] ?? [] as $attendee) {
            if (($attendee['self'] ?? false) === true && ($attendee['responseStatus'] ?? null) === 'declined') {
                return true;
            }
        }

        return false;
    }

    /**
     * 時刻付き予定は dateTime、終日予定は start.date の salon_timezone 00:00 〜 end.date（排他）の 00:00。
     * 複数日にまたがる終日予定も1本のブロックとして返す。
     *
     * @param  array<string, mixed>  $event
     * @return array{0: Carbon|null, 1: Carbon|null}
     */
    private function extractInterval(array $event): array
    {
        $start = $event['start'] ?? null;
        $end = $event['end'] ?? null;

        if (! is_array($start) || ! is_array($end)) {
            return [null, null];
        }

        if (isset($start['dateTime'], $end['dateTime'])) {
            return [
                Carbon::parse($start['dateTime'])->utc(),
                Carbon::parse($end['dateTime'])->utc(),
            ];
        }

        if (isset($start['date'], $end['date'])) {
            $timezone = config('app.salon_timezone');

            return [
                Carbon::parse($start['date'], $timezone)->startOfDay()->utc(),
                Carbon::parse($end['date'], $timezone)->startOfDay()->utc(),
            ];
        }

        return [null, null];
    }

    /**
     * @return array{from: Carbon, to: Carbon}
     */
    private function syncWindow(): array
    {
        $timezone = config('app.salon_timezone');

        return [
            'from' => Carbon::now()->utc(),
            'to' => Carbon::today($timezone)->addDays(self::SYNC_WINDOW_DAYS)->utc(),
        ];
    }
}
