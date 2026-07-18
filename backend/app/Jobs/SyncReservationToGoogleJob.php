<?php

namespace App\Jobs;

use App\Repositories\ReservationRepository;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleAuthException;
use App\Services\Google\GoogleEventSyncService;
use App\Services\Google\GoogleRateLimitException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 予約1件を Google カレンダーへ送信同期する。
 * 実行時点の予約を DB から再読み込みして書くため、ペイロードは固定せず引数は予約IDと旧接続特定用のみとする
 * （遅延・リトライしても常に最新状態へ収束する。google-calendar.md 送信同期）。
 */
class SyncReservationToGoogleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    public function __construct(
        public readonly int $reservationId,
        public readonly ?int $previousUserId = null,
        public readonly ?string $previousCalendarId = null,
    ) {}

    public function handle(
        ReservationRepository $reservationRepository,
        GoogleEventSyncService $syncService,
    ): void {
        $reservation = $reservationRepository->findForSync($this->reservationId);

        if ($reservation === null) {
            return;
        }

        try {
            $syncService->sync($reservation, $this->previousUserId, $this->previousCalendarId);
        } catch (GoogleAuthException $e) {
            // 再接続が必要（接続は needs_reconnect 済み）。回復にユーザー操作を要するためリトライしない
            Log::warning('Google 送信同期を認証失効のため打ち切りました。', [
                'reservation_id' => $this->reservationId,
            ]);
            $this->fail($e);
        } catch (GoogleRateLimitException $e) {
            // 429 は Retry-After に従う（固定バックオフで押し返さない）
            $this->release($e->retryAfter ?? 60);
        } catch (GoogleApiException $e) {
            // 接続失敗（0）・5xx はリトライ、その他の 4xx はログのみで打ち切る
            if ($e->status === 0 || $e->status >= 500) {
                throw $e;
            }

            Log::warning('Google 送信同期が回復不能なエラーのため打ち切りました。', [
                'reservation_id' => $this->reservationId,
                'status' => $e->status,
            ]);
            $this->fail($e);
        }
    }
}
