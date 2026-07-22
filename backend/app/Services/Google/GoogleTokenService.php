<?php

namespace App\Services\Google;

use App\Models\GoogleCalendarConnection;
use App\Repositories\GoogleCalendarConnectionRepository;

/**
 * 接続の access_token を有効な状態に保つ。
 * 期限切れ（および期限直前）なら refresh_token で更新して保存する。
 */
class GoogleTokenService
{
    /**
     * 実行中に期限切れになるのを避けるための猶予。
     */
    private const EXPIRY_LEEWAY_SECONDS = 60;

    public function __construct(
        private readonly GoogleClient $client,
        private readonly GoogleCalendarConnectionRepository $connections,
    ) {}

    /**
     * 有効な access_token を返す（必要なら更新する）。
     *
     * @throws GoogleAuthException refresh_token が失効している場合（接続は needs_reconnect になる）
     */
    public function accessTokenFor(GoogleCalendarConnection $connection): string
    {
        if (! $this->needsRefresh($connection)) {
            return $connection->access_token;
        }

        return $this->refresh($connection);
    }

    /**
     * 期限に関係なく refresh_token で access_token を強制更新して保存し、新しい値を返す。
     * API 呼び出しが 401 を返したときの再試行前に使う（要件のエラー表）。
     * refresh_token が失効していれば接続を needs_reconnect にして GoogleAuthException を投げる。
     *
     * @throws GoogleAuthException
     */
    public function forceRefresh(GoogleCalendarConnection $connection): string
    {
        return $this->refresh($connection);
    }

    /**
     * $call を有効な access_token で実行し、401 を受けたら refresh して同一リクエストを1回だけ再試行する。
     * 再試行でも 401、または refresh 自体が invalid_grant で失敗した場合はそのまま伝播する
     * （呼び出し側は needs_reconnect 済みとして打ち切る）。それ以外の例外もそのまま伝播する。
     *
     * @param  callable(string): mixed  $call
     *
     * @throws GoogleAuthException
     */
    public function runWithAuthRetry(GoogleCalendarConnection $connection, callable $call): mixed
    {
        $token = $this->accessTokenFor($connection);

        try {
            return $call($token);
        } catch (GoogleApiException $e) {
            if ($e->status !== 401) {
                throw $e;
            }
        }

        // 401 → refresh して新トークンで1回だけ再試行する（再試行の 401 はそのまま伝播）
        return $call($this->forceRefresh($connection));
    }

    private function needsRefresh(GoogleCalendarConnection $connection): bool
    {
        $expiresAt = $connection->token_expires_at;

        return $expiresAt === null
            || $expiresAt->copy()->utc()->subSeconds(self::EXPIRY_LEEWAY_SECONDS)->isPast();
    }

    private function refresh(GoogleCalendarConnection $connection): string
    {
        try {
            $token = $this->client->refreshAccessToken($connection->refresh_token);
        } catch (GoogleAuthException $e) {
            // invalid_grant = ユーザーが Google 側でアクセスを取り消した等。再接続が必要
            $this->connections->markNeedsReconnect($connection);

            throw $e;
        }

        $accessToken = $token['access_token'];

        $this->connections->update($connection, [
            'access_token' => $accessToken,
            'token_expires_at' => now()->utc()->addSeconds((int) ($token['expires_in'] ?? 3600)),
            // refresh_token は refresh 応答に含まれないのが通常。含まれた場合のみ差し替える
            ...isset($token['refresh_token']) ? ['refresh_token' => $token['refresh_token']] : [],
        ]);

        return $accessToken;
    }
}
