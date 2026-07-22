<?php

namespace App\Services\Google;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Enums\GoogleCalendarMode;
use App\Enums\ReservationStatus;
use App\Models\GoogleCalendarConnection;
use App\Models\Reservation;
use App\Repositories\GoogleCalendarConnectionRepository;
use App\Repositories\ReservationRepository;

/**
 * 送信同期（RB → Google）のビジネスロジック。
 * 予約1件を実行時点の状態で Google イベントへ反映する（作成・更新・削除）。
 * per_staff の担当スタッフ変更・対象カレンダー変更では旧接続・旧カレンダーのイベントを消してから作り直す。
 */
class GoogleEventSyncService
{
    public function __construct(
        private readonly GoogleClient $client,
        private readonly GoogleTokenService $tokens,
        private readonly GoogleCalendarConnectionRepository $connections,
        private readonly ReservationRepository $reservations,
        private readonly GoogleEventPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * @param  int|null  $previousUserId  変更前の担当スタッフID（per_staff の担当変更で旧接続を特定する）
     * @param  string|null  $previousCalendarId  変更前の対象カレンダー（カレンダー変更で旧イベントを特定する）
     *
     * @throws GoogleAuthException 認証失効（接続を needs_reconnect にして呼び出し側で打ち切る）
     * @throws GoogleRateLimitException レート制限（呼び出し側でリトライ）
     * @throws GoogleApiException その他の API 失敗
     */
    public function sync(Reservation $reservation, ?int $previousUserId = null, ?string $previousCalendarId = null): void
    {
        $connection = $this->resolveConnection($reservation);

        // 書き込み先が変わった場合は旧イベントを消し、google_event_id を切る（新規作成に落とす）
        $this->cleanupPreviousTarget($reservation, $previousUserId, $previousCalendarId, $connection);

        if ($this->shouldDelete($reservation)) {
            $this->deleteFromCurrent($reservation, $connection);

            return;
        }

        // 未接続・非アクティブは何もしない
        if ($connection === null || $connection->status !== GoogleCalendarConnectionStatus::Active) {
            return;
        }

        $this->writeEvent($connection, $reservation);
    }

    private function shouldDelete(Reservation $reservation): bool
    {
        return $reservation->trashed()
            || in_array($reservation->status, [ReservationStatus::Cancelled, ReservationStatus::NoShow], true);
    }

    private function resolveConnection(Reservation $reservation): ?GoogleCalendarConnection
    {
        return match ($reservation->salon->google_calendar_mode) {
            GoogleCalendarMode::PerStaff => $this->connections->findBySalonAndUser($reservation->salon_id, $reservation->user_id),
            GoogleCalendarMode::Shared => $this->connections->findSharedBySalon($reservation->salon_id),
            default => null,
        };
    }

    /**
     * 担当変更（別アカウント）・カレンダー変更（同一アカウント内の移し替え）では、
     * 旧カレンダーの RB 由来イベントを削除してから google_event_id を切り、後段で新規作成させる。
     */
    private function cleanupPreviousTarget(
        Reservation $reservation,
        ?int $previousUserId,
        ?string $previousCalendarId,
        ?GoogleCalendarConnection $connection,
    ): void {
        if ($reservation->google_event_id === null) {
            return;
        }

        // per_staff の担当変更のみ旧接続（変更前スタッフ）のカレンダーから作り直す（Business Rules 15）。
        // shared は共有接続に書き続けるため書き込み先が変わらず、通常の updateEvent で足りる
        // （題名の担当スタッフ名も payload 再生成で更新される）。
        if ($reservation->salon->google_calendar_mode === GoogleCalendarMode::PerStaff
            && $previousUserId !== null
            && $previousUserId !== $reservation->user_id) {
            $oldConnection = $this->connections->findBySalonAndUser($reservation->salon_id, $previousUserId);

            if ($oldConnection === null) {
                // 旧スタッフが未接続: best-effort で旧イベントを残し、紐付けを切って新接続で作成する
                $this->clearEventId($reservation);

                return;
            }

            $this->detachFromPreviousCalendar($reservation, $oldConnection, $oldConnection->calendar_id);

            return;
        }

        // 対象カレンダー変更: 同一接続の旧カレンダーから作り直す
        if ($previousCalendarId !== null && $connection !== null && $previousCalendarId !== $connection->calendar_id) {
            $this->detachFromPreviousCalendar($reservation, $connection, $previousCalendarId);
        }
    }

    /**
     * 旧カレンダーの RB 由来イベントを削除して紐付けを切り、後段の新規作成に落とす。
     * - 旧接続が非アクティブ（needs_reconnect）: 削除をスキップしジョブを落とさない。best-effort で
     *   旧イベントを残し紐付けを切って新規作成へ進む
     * - 実削除に成功: 紐付けを切って新規作成へ進む
     * - 404 / 410（旧側に存在しない）: 現カレンダーへ既に移っている可能性があるため紐付けを保持し、
     *   通常の update 経路（404 なら insert フォールバック）に委ねる。重複孤児を作らない
     */
    private function detachFromPreviousCalendar(Reservation $reservation, GoogleCalendarConnection $oldConnection, string $calendarId): void
    {
        if ($oldConnection->status === GoogleCalendarConnectionStatus::Active) {
            $deleted = $this->deleteEventBestEffort($oldConnection, $calendarId, $reservation->google_event_id);

            if (! $deleted) {
                return;
            }
        }

        $this->clearEventId($reservation);
    }

    private function deleteFromCurrent(Reservation $reservation, ?GoogleCalendarConnection $connection): void
    {
        if ($reservation->google_event_id === null) {
            return;
        }

        // アクティブ接続が無ければ削除できない。紐付けを保持して no-op とする
        // （削除せず null クリアすると、次の受信同期で当該イベントが突合外れになり phantom busy を生む）
        if ($connection === null || $connection->status !== GoogleCalendarConnectionStatus::Active) {
            return;
        }

        // 実削除（成功、または 404 / 410 の冪等成功）した場合にのみ紐付けを切る。
        // その他の API 失敗・認証失効は deleteEventBestEffort が送出し、null クリアに至らない
        $this->deleteEventBestEffort($connection, $connection->calendar_id, $reservation->google_event_id);

        $this->clearEventId($reservation);
    }

    private function writeEvent(GoogleCalendarConnection $connection, Reservation $reservation): void
    {
        $payload = $this->payloadBuilder->build($connection, $reservation);

        if ($reservation->google_event_id === null) {
            $this->insert($connection, $reservation, $payload);

            return;
        }

        try {
            $this->callWithAuth($connection, fn (string $token) => $this->client->updateEvent($token, $connection->calendar_id, $reservation->google_event_id, $payload));
        } catch (GoogleApiException $e) {
            // 404 / 410 = 対象イベントが存在しない → 作成し直して ID を差し替える
            if (in_array($e->status, [404, 410], true)) {
                $this->insert($connection, $reservation, $payload);

                return;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insert(GoogleCalendarConnection $connection, Reservation $reservation, array $payload): void
    {
        $event = $this->callWithAuth($connection, fn (string $token) => $this->client->insertEvent($token, $connection->calendar_id, $payload));

        $this->reservations->updateForSync($reservation, ['google_event_id' => $event['id']]);
        $reservation->google_event_id = $event['id'];
    }

    /**
     * 旧カレンダーからの削除を best-effort で実行する。
     * 実削除に成功したら true、404 / 410（既に存在しない）は冪等成功として false を返す。
     * 認証失効・その他の API 失敗は送出する（呼び出し側・ジョブが打ち切り／リトライを判断する）。
     */
    private function deleteEventBestEffort(GoogleCalendarConnection $connection, string $calendarId, string $eventId): bool
    {
        try {
            $this->callWithAuth($connection, fn (string $token) => $this->client->deleteEvent($token, $calendarId, $eventId));
        } catch (GoogleApiException $e) {
            if (in_array($e->status, [404, 410], true)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    /**
     * Google API 呼び出しを 401 → refresh → 1回再試行で実行する。
     * 認証失効（GoogleAuthException）は接続を needs_reconnect にして送出する。
     *
     * @param  callable(string): mixed  $call
     */
    private function callWithAuth(GoogleCalendarConnection $connection, callable $call): mixed
    {
        try {
            return $this->tokens->runWithAuthRetry($connection, $call);
        } catch (GoogleAuthException $e) {
            // GoogleTokenService が invalid_grant で既に needs_reconnect にしているが冪等に確実化する
            $this->connections->markNeedsReconnect($connection);

            throw $e;
        }
    }

    private function clearEventId(Reservation $reservation): void
    {
        $this->reservations->updateForSync($reservation, ['google_event_id' => null]);
        $reservation->google_event_id = null;
    }
}
