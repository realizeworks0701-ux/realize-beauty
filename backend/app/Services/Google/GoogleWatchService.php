<?php

namespace App\Services\Google;

use App\Models\GoogleCalendarConnection;
use App\Repositories\GoogleCalendarConnectionRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 対象カレンダーへの push 通知（watch チャネル）の開設・停止。
 * OAuth コールバック・対象カレンダー変更・接続解除で共用する。
 */
class GoogleWatchService
{
    /** webhook 検証用の秘密値（CSPRNG 由来・32文字以上）。ADR-025 §5 / NFR 2 */
    private const CHANNEL_TOKEN_LENGTH = 40;

    public function __construct(
        private readonly GoogleClient $client,
        private readonly GoogleCalendarConnectionRepository $connections,
    ) {}

    /**
     * 対象カレンダーに新しい watch チャネルを開設し、応答の resourceId / expiration を保存する。
     * チャネルの TTL は Google が決めるため、独自の TTL を仮定せず応答をそのまま保存する。
     */
    public function open(GoogleCalendarConnection $connection, string $accessToken): void
    {
        $channelId = (string) Str::uuid();
        $channelToken = Str::random(self::CHANNEL_TOKEN_LENGTH);
        $address = rtrim(config('app.url'), '/').'/api/google/calendar/webhook';

        $channel = $this->client->watch($accessToken, $connection->calendar_id, $channelId, $channelToken, $address);

        $this->connections->update($connection, [
            'channel_id' => $channelId,
            'channel_token' => $channelToken,
            'channel_resource_id' => $channel['resourceId'],
            'channel_expires_at' => isset($channel['expiration'])
                ? Carbon::createFromTimestampMs((int) $channel['expiration'])
                : null,
        ]);
    }

    /**
     * 現在の watch チャネルを停止する（best-effort。失敗はログのみで続行する）。
     * needs_reconnect ではトークン更新も失敗しうるため認証失効も飲み込む。
     */
    public function stopBestEffort(GoogleCalendarConnection $connection, string $accessToken): void
    {
        if ($connection->channel_id === null || $connection->channel_resource_id === null) {
            return;
        }

        try {
            $this->client->stopChannel($accessToken, $connection->channel_id, $connection->channel_resource_id);
        } catch (GoogleApiException|GoogleAuthException $e) {
            Log::warning('Google watch チャネルの停止に失敗しました。', [
                'connection_id' => $connection->id,
                'status' => $e instanceof GoogleApiException ? $e->status : null,
            ]);
        }
    }
}
