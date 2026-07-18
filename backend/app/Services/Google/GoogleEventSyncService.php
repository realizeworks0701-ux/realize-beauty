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
 * 担当スタッフ変更・対象カレンダー変更では旧接続・旧カレンダーのイベントを消してから作り直す。
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

        // per_staff の担当変更: 旧接続（変更前スタッフ）のカレンダーから削除する
        if ($previousUserId !== null && $previousUserId !== $reservation->user_id) {
            $oldConnection = $this->connections->findBySalonAndUser($reservation->salon_id, $previousUserId);

            if ($oldConnection !== null) {
                $this->deleteEventBestEffort($oldConnection, $oldConnection->calendar_id, $reservation->google_event_id);
            }

            $this->reservations->updateForSync($reservation, ['google_event_id' => null]);
            $reservation->google_event_id = null;

            return;
        }

        // 対象カレンダー変更: 同一接続の旧カレンダーから削除する
        if ($previousCalendarId !== null && $connection !== null && $previousCalendarId !== $connection->calendar_id) {
            $this->deleteEventBestEffort($connection, $previousCalendarId, $reservation->google_event_id);

            $this->reservations->updateForSync($reservation, ['google_event_id' => null]);
            $reservation->google_event_id = null;
        }
    }

    private function deleteFromCurrent(Reservation $reservation, ?GoogleCalendarConnection $connection): void
    {
        if ($connection !== null
            && $connection->status === GoogleCalendarConnectionStatus::Active
            && $reservation->google_event_id !== null) {
            $this->deleteEventBestEffort($connection, $connection->calendar_id, $reservation->google_event_id);
        }

        // 削除成功・対象接続なしのいずれでも紐付けを切る（受信同期の逆引き照合をヒットさせない）
        if ($reservation->google_event_id !== null) {
            $this->reservations->updateForSync($reservation, ['google_event_id' => null]);
            $reservation->google_event_id = null;
        }
    }

    private function writeEvent(GoogleCalendarConnection $connection, Reservation $reservation): void
    {
        $accessToken = $this->accessToken($connection);
        $payload = $this->payloadBuilder->build($connection, $reservation);

        if ($reservation->google_event_id === null) {
            $this->insert($connection, $reservation, $accessToken, $payload);

            return;
        }

        try {
            $this->client->updateEvent($accessToken, $connection->calendar_id, $reservation->google_event_id, $payload);
        } catch (GoogleAuthException $e) {
            $this->connections->markNeedsReconnect($connection);

            throw $e;
        } catch (GoogleApiException $e) {
            // 404 / 410 = 対象イベントが存在しない → 作成し直して ID を差し替える
            if (in_array($e->status, [404, 410], true)) {
                $this->insert($connection, $reservation, $accessToken, $payload);

                return;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function insert(GoogleCalendarConnection $connection, Reservation $reservation, string $accessToken, array $payload): void
    {
        try {
            $event = $this->client->insertEvent($accessToken, $connection->calendar_id, $payload);
        } catch (GoogleAuthException $e) {
            $this->connections->markNeedsReconnect($connection);

            throw $e;
        }

        $this->reservations->updateForSync($reservation, ['google_event_id' => $event['id']]);
        $reservation->google_event_id = $event['id'];
    }

    /**
     * 404 / 410（既に存在しない）は冪等成功として無視する。認証失効のみ需再接続として送出する。
     */
    private function deleteEventBestEffort(GoogleCalendarConnection $connection, string $calendarId, string $eventId): void
    {
        try {
            $accessToken = $this->accessToken($connection);
            $this->client->deleteEvent($accessToken, $calendarId, $eventId);
        } catch (GoogleAuthException $e) {
            $this->connections->markNeedsReconnect($connection);

            throw $e;
        } catch (GoogleApiException $e) {
            if (in_array($e->status, [404, 410], true)) {
                return;
            }

            throw $e;
        }
    }

    private function accessToken(GoogleCalendarConnection $connection): string
    {
        try {
            return $this->tokens->accessTokenFor($connection);
        } catch (GoogleAuthException $e) {
            // GoogleTokenService が invalid_grant で既に needs_reconnect にしているが冪等に確実化する
            $this->connections->markNeedsReconnect($connection);

            throw $e;
        }
    }
}
