<?php

namespace App\Services\Google;

use App\Jobs\SyncGoogleCalendarJob;
use App\Repositories\GoogleCalendarConnectionRepository;
use Illuminate\Support\Facades\Log;

/**
 * Google カレンダーの push 通知（watch チャネル）を受理する。
 * 認証なし・throttle なしのため、3段検証（未知チャネル / token 不一致 / resourceId 不一致）で守り、
 * いずれに該当しても常に 200（ログのみ。Google のリトライ暴走防止）。ADR-025 §5 / NFR 2。
 */
class GoogleCalendarWebhookService
{
    public function __construct(
        private readonly GoogleCalendarConnectionRepository $connections,
    ) {}

    public function handle(?string $channelId, ?string $channelToken, ?string $resourceId, ?string $resourceState): void
    {
        // 1. 未知の X-Goog-Channel-ID
        if (! is_string($channelId) || $channelId === '') {
            Log::info('Google webhook: X-Goog-Channel-ID がありません。');

            return;
        }

        $connection = $this->connections->findByChannelId($channelId);

        if ($connection === null) {
            Log::info('Google webhook: 未知の channel_id を受信しました。');

            return;
        }

        // 2. X-Goog-Channel-Token の不一致（hash_equals でタイミング攻撃耐性）
        if (! is_string($channelToken) || $connection->channel_token === null
            || ! hash_equals($connection->channel_token, $channelToken)) {
            Log::warning('Google webhook: channel_token が一致しません。', ['connection_id' => $connection->id]);

            return;
        }

        // 3. X-Goog-Resource-ID の不一致（そのチャネルが実際に監視するカレンダーからの通知か確認）
        if (! is_string($resourceId) || $connection->channel_resource_id === null
            || ! hash_equals($connection->channel_resource_id, $resourceId)) {
            Log::warning('Google webhook: resourceId が一致しません。', ['connection_id' => $connection->id]);

            return;
        }

        // チャネル開設直後の疎通通知は何もしない
        if ($resourceState === 'sync') {
            return;
        }

        SyncGoogleCalendarJob::dispatch($connection->id);
    }
}
