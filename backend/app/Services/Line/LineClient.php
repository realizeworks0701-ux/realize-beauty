<?php

namespace App\Services\Line;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * LINE Messaging API クライアント（必要APIは bot info / reply / push の3つのみ。ADR-024）。
 */
class LineClient
{
    /**
     * bot 情報を取得する（接続確認用）。
     *
     * @return array{userId: string, basicId: string, displayName: string}
     */
    public function getBotInfo(string $token): array
    {
        $response = $this->send(fn () => $this->request($token)->get('/v2/bot/info'));

        $botInfo = $response->json();

        if (! is_array($botInfo) || ! is_string($botInfo['userId'] ?? null)) {
            throw new LineApiException($response->status(), 'LINE bot info のレスポンスが期待した形式ではありません。');
        }

        return $botInfo;
    }

    /**
     * 応答メッセージを送信する。
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function reply(string $token, string $replyToken, array $messages): void
    {
        $this->send(fn () => $this->request($token)->post('/v2/bot/message/reply', [
            'replyToken' => $replyToken,
            'messages' => $messages,
        ]));
    }

    /**
     * プッシュメッセージを送信する。
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function push(string $token, string $to, array $messages): void
    {
        $this->send(fn () => $this->request($token)->post('/v2/bot/message/push', [
            'to' => $to,
            'messages' => $messages,
        ]));
    }

    private function request(string $token): PendingRequest
    {
        $config = config('services.line');

        return Http::withToken($token)
            ->baseUrl($config['base_url'])
            ->timeout((int) $config['timeout'])
            ->acceptJson();
    }

    /**
     * 接続失敗（タイムアウト等）も LineApiException（status 0）に揃え、
     * 呼び出し側のエラー分岐を LineApiException 1系統にする。
     *
     * @param  callable(): Response  $request
     */
    private function send(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new LineApiException(0, 'LINE API への接続に失敗しました: '.$e->getMessage());
        }

        return $this->ensureSuccess($response);
    }

    /**
     * 429 は LineRateLimitException、その他の 4xx/5xx は LineApiException として区別する。
     */
    private function ensureSuccess(Response $response): Response
    {
        if ($response->status() === 429) {
            throw new LineRateLimitException;
        }

        if ($response->failed()) {
            throw new LineApiException($response->status());
        }

        return $response;
    }
}
