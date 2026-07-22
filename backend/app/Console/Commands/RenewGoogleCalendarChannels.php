<?php

namespace App\Console\Commands;

use App\Models\GoogleCalendarConnection;
use App\Repositories\GoogleCalendarConnectionRepository;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleClient;
use App\Services\Google\GoogleTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * 期限が迫った watch チャネルを張り直す（Google にチャネル更新 API は無い）。
 * 新しい channel_id で watch → 旧チャネルを stop の順とし、無通知の窓を作らない。
 * 接続時の watch 開設は best-effort のため、未開設（channel_id が null）の Active 接続も
 * ここで開設する（旧チャネルが無ければ stop は行わない）。
 */
class RenewGoogleCalendarChannels extends Command
{
    protected $signature = 'google-calendar:renew-channels';

    protected $description = '期限が迫った Google カレンダーの watch チャネルを張り直す';

    /** webhook 検証用の秘密値（CSPRNG 由来・32文字以上）の長さ */
    private const CHANNEL_TOKEN_LENGTH = 40;

    public function handle(
        GoogleCalendarConnectionRepository $connectionRepository,
        GoogleClient $client,
        GoogleTokenService $tokens,
    ): int {
        $connections = $connectionRepository->listExpiringChannels(now()->addDay());

        $renewed = 0;

        foreach ($connections as $connection) {
            try {
                $this->renew($connection, $connectionRepository, $client, $tokens);
                $renewed++;
            } catch (GoogleApiException $e) {
                // watch 失敗時は status を変えずレコードも更新しない（次回実行でリトライ。再実行は安全）
                Log::warning('Google watch チャネルの張り直しに失敗しました。', [
                    'connection_id' => $connection->id,
                    'status' => $e->status,
                ]);
            }
        }

        $this->info("watch チャネルを {$renewed} 件張り直しました。");

        return self::SUCCESS;
    }

    private function renew(
        GoogleCalendarConnection $connection,
        GoogleCalendarConnectionRepository $connectionRepository,
        GoogleClient $client,
        GoogleTokenService $tokens,
    ): void {
        // 1. 旧チャネルを退避する（レコードは1組しか持たないため、先に上書きすると stop を呼べなくなる）
        $oldChannelId = $connection->channel_id;
        $oldResourceId = $connection->channel_resource_id;

        $newChannelId = (string) Str::uuid();
        $newChannelToken = Str::random(self::CHANNEL_TOKEN_LENGTH);
        $address = rtrim(config('app.url'), '/').'/api/google/calendar/webhook';

        $accessToken = $tokens->accessTokenFor($connection);

        // 2. 新しい channel_id / token で watch する
        $channel = $client->watch($accessToken, $connection->calendar_id, $newChannelId, $newChannelToken, $address);

        // 3. 成功したら応答の resourceId / expiration でレコードを更新する
        $connectionRepository->update($connection, [
            'channel_id' => $newChannelId,
            'channel_token' => $newChannelToken,
            'channel_resource_id' => $channel['resourceId'],
            'channel_expires_at' => isset($channel['expiration'])
                ? Carbon::createFromTimestampMs((int) $channel['expiration'])
                : null,
        ]);

        // 4. 退避した旧チャネルを stop する（失敗はログのみ。期限切れで自然消滅する）
        if ($oldChannelId !== null && $oldResourceId !== null) {
            try {
                $client->stopChannel($accessToken, $oldChannelId, $oldResourceId);
            } catch (GoogleApiException $e) {
                Log::warning('旧 watch チャネルの停止に失敗しました。', [
                    'connection_id' => $connection->id,
                    'status' => $e->status,
                ]);
            }
        }
    }
}
