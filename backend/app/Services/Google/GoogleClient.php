<?php

namespace App\Services\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google Calendar / OAuth クライアント（公式SDK不使用。必要APIのみ。ADR-025 §9）。
 *
 * OAuth 系（oauth2.googleapis.com）と API 系（www.googleapis.com）は別ホストのため、
 * ベースURLを config から個別に取得する。
 */
class GoogleClient
{
    /**
     * 認可コードをトークンに交換する。
     *
     * @return array{access_token: string, refresh_token?: string, expires_in: int, ...}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = $this->send(fn () => $this->oauthRequest()->asForm()->post($this->config('token_url'), [
            'code' => $code,
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]));

        return $this->decodeTokenResponse($response);
    }

    /**
     * refresh_token で access_token を更新する。
     * refresh_token 自体が失効している場合は invalid_grant となり GoogleAuthException を投げる。
     *
     * @return array{access_token: string, expires_in: int, ...}
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = $this->send(fn () => $this->oauthRequest()->asForm()->post($this->config('token_url'), [
            'refresh_token' => $refreshToken,
            'client_id' => $this->config('client_id'),
            'client_secret' => $this->config('client_secret'),
            'grant_type' => 'refresh_token',
        ]));

        return $this->decodeTokenResponse($response);
    }

    /**
     * Google 側の grant を失効させる（連携解除の手順2）。
     */
    public function revokeToken(string $token): void
    {
        $this->send(fn () => $this->oauthRequest()->asForm()->post($this->config('revoke_url'), [
            'token' => $token,
        ]));
    }

    /**
     * 接続アカウントのカレンダー一覧（選択UI用・google_account_email の取得元）。
     * nextPageToken を辿って全ページを結合する（100件超のアカウントで1ページ目だけ取ると欠落する）。
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCalendars(string $accessToken): array
    {
        $items = [];
        $pageToken = null;

        do {
            $params = ['maxResults' => 250];

            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $body = $this->send(
                fn () => $this->apiRequest($accessToken)->get('/calendar/v3/users/me/calendarList', $params)
            )->json();

            if (! is_array($body)) {
                break;
            }

            foreach ($body['items'] ?? [] as $item) {
                $items[] = $item;
            }

            $pageToken = $body['nextPageToken'] ?? null;
        } while (is_string($pageToken) && $pageToken !== '');

        return $items;
    }

    /**
     * イベント一覧を1ページ取得する（ページングは呼び出し側が nextPageToken で辿る）。
     *
     * $params は syncToken / timeMin / timeMax / pageToken / singleEvents 等をそのまま渡す。
     * syncToken は timeMin / timeMax と併用できない（併用は 400）ため、絞り込みの有無は呼び出し側の責務。
     *
     * @param  array<string, mixed>  $params
     * @return array{items?: array<int, array<string, mixed>>, nextPageToken?: string, nextSyncToken?: string}
     *
     * @throws GoogleSyncTokenExpiredException syncToken 失効（410）。全同期し直す契機
     */
    public function listEvents(string $accessToken, string $calendarId, array $params = []): array
    {
        $response = $this->send(
            fn () => $this->apiRequest($accessToken)->get($this->eventsPath($calendarId), $params),
            syncTokenAware: true,
        );

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function insertEvent(string $accessToken, string $calendarId, array $event): array
    {
        $response = $this->send(fn () => $this->apiRequest($accessToken)->post($this->eventsPath($calendarId), $event));

        $body = $response->json();

        if (! is_array($body) || ! is_string($body['id'] ?? null)) {
            throw new GoogleApiException($response->status(), 'Google events.insert のレスポンスが期待した形式ではありません。');
        }

        return $body;
    }

    /**
     * イベントを部分更新する。events.update（PUT・全置換）ではなく PATCH を使う。
     * PUT は sequence 一致を要求するため、会議室応答や他アプリ編集で sequence が進んだ
     * イベントへの全置換が 400 で恒久失敗する。PATCH は部分更新で sequence 不要のため回避できる。
     * 404 / 410（対象イベントが存在しない）は呼び出し側が insert へフォールバックする。
     *
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function updateEvent(string $accessToken, string $calendarId, string $eventId, array $event): array
    {
        $response = $this->send(fn () => $this->apiRequest($accessToken)
            ->patch($this->eventsPath($calendarId).'/'.rawurlencode($eventId), $event));

        $body = $response->json();

        return is_array($body) ? $body : [];
    }

    /**
     * イベントを削除する。
     * 404 / 410（既に存在しない）は GoogleApiException の status で呼び出し側が冪等成功として扱う。
     */
    public function deleteEvent(string $accessToken, string $calendarId, string $eventId): void
    {
        $this->send(fn () => $this->apiRequest($accessToken)
            ->delete($this->eventsPath($calendarId).'/'.rawurlencode($eventId)));
    }

    /**
     * push 通知の watch チャネルを開設する。
     * TTL は Google が決める（要求値より短くされうる）ため、応答の expiration をそのまま保存すること。
     *
     * @return array{id?: string, resourceId?: string, expiration?: string, ...}
     */
    public function watch(string $accessToken, string $calendarId, string $channelId, string $channelToken, string $address): array
    {
        $response = $this->send(fn () => $this->apiRequest($accessToken)->post($this->eventsPath($calendarId).'/watch', [
            'id' => $channelId,
            'type' => 'web_hook',
            'address' => $address,
            'token' => $channelToken,
        ]));

        $body = $response->json();

        if (! is_array($body) || ! is_string($body['resourceId'] ?? null)) {
            throw new GoogleApiException($response->status(), 'Google channels.watch のレスポンスが期待した形式ではありません。');
        }

        return $body;
    }

    /**
     * watch チャネルを停止する（id と resourceId の両方が必須）。
     */
    public function stopChannel(string $accessToken, string $channelId, string $resourceId): void
    {
        $this->send(fn () => $this->apiRequest($accessToken)->post('/calendar/v3/channels/stop', [
            'id' => $channelId,
            'resourceId' => $resourceId,
        ]));
    }

    /**
     * calendar_id は primary エイリアスのほか実 id（メールアドレス形式）を取りうるため、
     * パスセグメントとしてエンコードする。
     */
    private function eventsPath(string $calendarId): string
    {
        return '/calendar/v3/calendars/'.rawurlencode($calendarId).'/events';
    }

    private function apiRequest(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)
            ->baseUrl($this->config('api_base_url'))
            ->timeout((int) $this->config('timeout'))
            ->acceptJson();
    }

    private function oauthRequest(): PendingRequest
    {
        return Http::timeout((int) $this->config('timeout'))->acceptJson();
    }

    private function config(string $key): mixed
    {
        return config("services.google.{$key}");
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeTokenResponse(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body) || ! is_string($body['access_token'] ?? null)) {
            throw new GoogleApiException($response->status(), 'Google トークンエンドポイントのレスポンスが期待した形式ではありません。');
        }

        return $body;
    }

    /**
     * 接続失敗（タイムアウト等）も GoogleApiException（status 0）に揃え、
     * 呼び出し側のエラー分岐を GoogleApiException 1系統にする。
     *
     * @param  callable(): Response  $request
     * @param  bool  $syncTokenAware  events.list のみ 410 を syncToken 失効として扱う
     *                                （delete の 410 は「既に存在しない」であり意味が異なる）
     */
    private function send(callable $request, bool $syncTokenAware = false): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new GoogleApiException(0, 'Google API への接続に失敗しました: '.$e->getMessage());
        }

        return $this->ensureSuccess($response, $syncTokenAware);
    }

    private function ensureSuccess(Response $response, bool $syncTokenAware): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $status = $response->status();
        $reason = $this->errorReason($response);

        if ($syncTokenAware && $status === 410) {
            throw new GoogleSyncTokenExpiredException;
        }

        // invalid_grant = refresh_token の失効・取り消し。再接続が必要
        if ($status === 401 || $reason === 'invalid_grant') {
            throw new GoogleAuthException($status, "Google API authentication failed ({$status}".($reason !== null ? ": {$reason}" : '').').');
        }

        if ($status === 429 || ($status === 403 && in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true))) {
            throw new GoogleRateLimitException($status, $this->retryAfter($response));
        }

        throw new GoogleApiException($status);
    }

    /**
     * OAuth 系は {"error": "invalid_grant"}、API 系は {"error": {"errors": [{"reason": "..."}]}} と
     * エラー形式が異なるため、両方から reason を取り出す。
     */
    private function errorReason(Response $response): ?string
    {
        $error = $response->json('error');

        if (is_string($error)) {
            return $error;
        }

        if (is_array($error)) {
            $reason = $error['errors'][0]['reason'] ?? null;

            return is_string($reason) ? $reason : null;
        }

        return null;
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? (int) $header : null;
    }
}
