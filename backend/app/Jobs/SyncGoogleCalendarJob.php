<?php

namespace App\Jobs;

use App\Enums\Feature;
use App\Enums\GoogleCalendarConnectionStatus;
use App\Repositories\GoogleCalendarConnectionRepository;
use App\Services\Billing\EntitlementService;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleAuthException;
use App\Services\Google\GoogleCalendarSyncService;
use App\Services\Google\GoogleRateLimitException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * push 通知を契機に接続1件の受信同期（増分 / 全同期）を実行する。
 *
 * ShouldBeUniqueUntilProcessing とし、処理開始時にロックを解放する。
 * ShouldBeUnique では実行中に届いた通知が破棄され、最後の変更が反映されない
 * （外部予定が busy にならず公開予約が入る事故になる。ADR-025 §5 / Non-Functional 4）。
 */
class SyncGoogleCalendarJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /** ワーカー異常終了時のロック残留で同期が恒久停止しないよう明示する（10分） */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $connectionId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(
        GoogleCalendarConnectionRepository $connectionRepository,
        GoogleCalendarSyncService $syncService,
        EntitlementService $entitlements,
    ): void {
        $connection = $connectionRepository->find($this->connectionId);

        // 解除済み・要再接続・プラン対象外はスキップ（needs_reconnect の同期はリトライせず打ち切る）
        if ($connection === null
            || $connection->status === GoogleCalendarConnectionStatus::NeedsReconnect
            || ! $entitlements->can($connection->salon_id, Feature::GoogleCalendar)) {
            return;
        }

        try {
            $syncService->sync($connection);
        } catch (GoogleAuthException $e) {
            $connectionRepository->markNeedsReconnect($connection);
            Log::warning('Google 受信同期を認証失効のため打ち切りました。', [
                'connection_id' => $this->connectionId,
            ]);
            $this->fail($e);
        } catch (GoogleRateLimitException $e) {
            // 429 は Retry-After に従う
            $this->release($e->retryAfter ?? 60);
        } catch (GoogleApiException $e) {
            // 接続失敗（0）・5xx はリトライ、その他の 4xx はログのみで打ち切る
            if ($e->status === 0 || $e->status >= 500) {
                throw $e;
            }

            Log::warning('Google 受信同期が回復不能なエラーのため打ち切りました。', [
                'connection_id' => $this->connectionId,
                'status' => $e->status,
            ]);
            $this->fail($e);
        }
    }
}
