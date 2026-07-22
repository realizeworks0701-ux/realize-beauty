<?php

namespace Tests\Feature;

use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleAuthException;
use App\Services\Google\GoogleClient;
use App\Services\Google\GoogleRateLimitException;
use App\Services\Google\GoogleSyncTokenExpiredException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleClientTest extends TestCase
{
    private const ACCESS_TOKEN = 'ya29.test-access-token';

    private GoogleClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);

        Http::preventStrayRequests();

        $this->client = new GoogleClient;
    }

    public function test_exchange_code_posts_authorization_code_and_returns_tokens(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 3599,
            ]),
        ]);

        $token = $this->client->exchangeCode('auth-code', 'https://api.example.com/api/v1/google-calendar/callback');

        $this->assertSame('new-access-token', $token['access_token']);
        $this->assertSame('new-refresh-token', $token['refresh_token']);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://oauth2.googleapis.com/token'
                && $request['grant_type'] === 'authorization_code'
                && $request['code'] === 'auth-code'
                && $request['client_secret'] === 'test-client-secret'
                && $request['redirect_uri'] === 'https://api.example.com/api/v1/google-calendar/callback';
        });
    }

    public function test_refresh_access_token_posts_refresh_grant(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed', 'expires_in' => 3599]),
        ]);

        $token = $this->client->refreshAccessToken('stored-refresh-token');

        $this->assertSame('refreshed', $token['access_token']);

        Http::assertSent(fn (Request $request) => $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'stored-refresh-token');
    }

    public function test_refresh_access_token_throws_auth_exception_on_invalid_grant(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(GoogleAuthException::class);

        $this->client->refreshAccessToken('revoked-refresh-token');
    }

    public function test_revoke_token_posts_to_revoke_endpoint(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);

        $this->client->revokeToken('stored-refresh-token');

        Http::assertSent(fn (Request $request) => $request->url() === 'https://oauth2.googleapis.com/revoke'
            && $request['token'] === 'stored-refresh-token');
    }

    public function test_list_calendars_returns_items(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::response([
                'items' => [
                    ['id' => 'owner@example.com', 'primary' => true],
                    ['id' => 'other@group.calendar.google.com'],
                ],
            ]),
        ]);

        $calendars = $this->client->listCalendars(self::ACCESS_TOKEN);

        $this->assertCount(2, $calendars);
        $this->assertSame('owner@example.com', $calendars[0]['id']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN));
    }

    /**
     * nextPageToken を辿って全ページを結合する（1ページ目だけ取ると100件超のアカウントで欠落する）。
     */
    public function test_list_calendars_follows_pagination_and_merges_all_pages(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/users/me/calendarList*' => Http::sequence()
                ->push(['items' => [['id' => 'owner@example.com', 'primary' => true]], 'nextPageToken' => 'page-2'])
                ->push(['items' => [['id' => 'other@group.calendar.google.com']]]),
        ]);

        $calendars = $this->client->listCalendars(self::ACCESS_TOKEN);

        $this->assertCount(2, $calendars);
        $this->assertSame('owner@example.com', $calendars[0]['id']);
        $this->assertSame('other@group.calendar.google.com', $calendars[1]['id']);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'maxResults=250'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'pageToken=page-2'));
    }

    public function test_list_events_passes_sync_token_and_returns_body(): void
    {
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/*' => Http::response([
                'items' => [['id' => 'evt-1']],
                'nextSyncToken' => 'sync-token-2',
            ]),
        ]);

        $body = $this->client->listEvents(self::ACCESS_TOKEN, 'primary', [
            'syncToken' => 'sync-token-1',
            'singleEvents' => 'true',
        ]);

        $this->assertSame('sync-token-2', $body['nextSyncToken']);
        $this->assertCount(1, $body['items']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/calendar/v3/calendars/primary/events')
            && str_contains($request->url(), 'syncToken=sync-token-1')
            && str_contains($request->url(), 'singleEvents=true'));
    }

    public function test_list_events_url_encodes_calendar_id(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['items' => []])]);

        $this->client->listEvents(self::ACCESS_TOKEN, 'owner@example.com');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/calendars/owner%40example.com/events'));
    }

    public function test_list_events_throws_sync_token_expired_on_410(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['code' => 410]], 410)]);

        $this->expectException(GoogleSyncTokenExpiredException::class);

        $this->client->listEvents(self::ACCESS_TOKEN, 'primary', ['syncToken' => 'expired']);
    }

    public function test_list_events_throws_auth_exception_on_401(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['code' => 401]], 401)]);

        $this->expectException(GoogleAuthException::class);

        $this->client->listEvents(self::ACCESS_TOKEN, 'primary');
    }

    public function test_list_events_throws_rate_limit_exception_on_429(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['code' => 429]], 429, ['Retry-After' => '30'])]);

        try {
            $this->client->listEvents(self::ACCESS_TOKEN, 'primary');
            $this->fail('GoogleRateLimitException が投げられませんでした。');
        } catch (GoogleRateLimitException $e) {
            $this->assertSame(429, $e->status);
            $this->assertSame(30, $e->retryAfter);
        }
    }

    public function test_throws_rate_limit_exception_on_403_rate_limit_exceeded(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response([
            'error' => ['code' => 403, 'errors' => [['reason' => 'rateLimitExceeded']]],
        ], 403)]);

        $this->expectException(GoogleRateLimitException::class);

        $this->client->listEvents(self::ACCESS_TOKEN, 'primary');
    }

    public function test_403_without_rate_limit_reason_is_a_plain_api_exception(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response([
            'error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]],
        ], 403)]);

        try {
            $this->client->listEvents(self::ACCESS_TOKEN, 'primary');
            $this->fail('GoogleApiException が投げられませんでした。');
        } catch (GoogleApiException $e) {
            $this->assertNotInstanceOf(GoogleRateLimitException::class, $e);
            $this->assertSame(403, $e->status);
        }
    }

    public function test_connection_exception_is_converted_to_api_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->client->listEvents(self::ACCESS_TOKEN, 'primary');
            $this->fail('GoogleApiException が投げられませんでした。');
        } catch (GoogleApiException $e) {
            $this->assertSame(0, $e->status);
            $this->assertStringContainsString('接続に失敗', $e->getMessage());
        }
    }

    public function test_insert_event_posts_event_and_returns_body(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'evt-new'])]);

        $event = $this->client->insertEvent(self::ACCESS_TOKEN, 'primary', [
            'summary' => 'カット（田中）',
            'extendedProperties' => ['private' => ['rb_reservation_id' => '1', 'rb_salon_id' => '2']],
        ]);

        $this->assertSame('evt-new', $event['id']);

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_contains($request->url(), '/calendars/primary/events')
            && $request['extendedProperties']['private']['rb_reservation_id'] === '1');
    }

    /**
     * events.update は PUT（全置換・sequence 一致要求）ではなく PATCH（部分更新）で送る。
     * PUT だと他アプリ編集等で sequence が進んだイベントへの更新が 400 で恒久失敗する。
     */
    public function test_update_event_patches_event_path(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'evt-1'])]);

        $this->client->updateEvent(self::ACCESS_TOKEN, 'primary', 'evt-1', ['summary' => '更新後']);

        Http::assertSent(fn (Request $request) => $request->method() === 'PATCH'
            && str_contains($request->url(), '/calendars/primary/events/evt-1'));
    }

    public function test_delete_event_sends_delete(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response('', 204)]);

        $this->client->deleteEvent(self::ACCESS_TOKEN, 'primary', 'evt-1');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), '/calendars/primary/events/evt-1'));
    }

    /**
     * delete の 410 は「既に存在しない」であり syncToken 失効ではないため、
     * 呼び出し側が status で冪等成功と判断できるよう GoogleApiException のまま投げる。
     */
    public function test_delete_event_410_is_a_plain_api_exception_not_sync_token_expired(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['code' => 410]], 410)]);

        try {
            $this->client->deleteEvent(self::ACCESS_TOKEN, 'primary', 'evt-gone');
            $this->fail('GoogleApiException が投げられませんでした。');
        } catch (GoogleApiException $e) {
            $this->assertNotInstanceOf(GoogleSyncTokenExpiredException::class, $e);
            $this->assertSame(410, $e->status);
        }
    }

    public function test_watch_posts_channel_and_returns_resource_id(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response([
            'id' => 'channel-1',
            'resourceId' => 'resource-1',
            'expiration' => '1783036800000',
        ])]);

        $channel = $this->client->watch(
            self::ACCESS_TOKEN,
            'primary',
            'channel-1',
            'channel-token-value',
            'https://api.example.com/api/google/calendar/webhook',
        );

        $this->assertSame('resource-1', $channel['resourceId']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/calendars/primary/events/watch')
            && $request['id'] === 'channel-1'
            && $request['type'] === 'web_hook'
            && $request['token'] === 'channel-token-value'
            && $request['address'] === 'https://api.example.com/api/google/calendar/webhook');
    }

    public function test_stop_channel_posts_id_and_resource_id(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response('', 204)]);

        $this->client->stopChannel(self::ACCESS_TOKEN, 'channel-1', 'resource-1');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/calendar/v3/channels/stop')
            && $request['id'] === 'channel-1'
            && $request['resourceId'] === 'resource-1');
    }
}
