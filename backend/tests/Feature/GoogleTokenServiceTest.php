<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Models\GoogleCalendarConnection;
use App\Services\Google\GoogleApiException;
use App\Services\Google\GoogleAuthException;
use App\Services\Google\GoogleClient;
use App\Services\Google\GoogleTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleTokenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_stored_access_token_when_still_valid(): void
    {
        Http::fake();

        $connection = GoogleCalendarConnection::factory()->create([
            'access_token' => 'valid-access-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->assertSame('valid-access-token', $this->service()->accessTokenFor($connection));

        Http::assertNothingSent();
    }

    public function test_refreshes_and_persists_token_when_expired(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'refreshed-access-token',
                'expires_in' => 3600,
            ]),
        ]);

        $connection = GoogleCalendarConnection::factory()->expiredToken()->create([
            'access_token' => 'stale-access-token',
            'refresh_token' => 'stored-refresh-token',
        ]);

        $accessToken = $this->service()->accessTokenFor($connection);

        $this->assertSame('refreshed-access-token', $accessToken);

        $fresh = $connection->fresh();
        $this->assertSame('refreshed-access-token', $fresh->access_token);
        // refresh 応答に refresh_token が無い場合は既存を維持する
        $this->assertSame('stored-refresh-token', $fresh->refresh_token);
        $this->assertSame(GoogleCalendarConnectionStatus::Active, $fresh->status);
        $this->assertTrue($fresh->token_expires_at->isFuture());
    }

    /**
     * 期限内でも残り60秒未満なら、実行中の期限切れを避けるため先に更新する。
     */
    public function test_refreshes_when_token_expires_within_leeway(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed', 'expires_in' => 3600]),
        ]);

        $connection = GoogleCalendarConnection::factory()->create([
            'access_token' => 'about-to-expire',
            'token_expires_at' => now()->addSeconds(30),
        ]);

        $this->assertSame('refreshed', $this->service()->accessTokenFor($connection));
    }

    public function test_invalid_grant_marks_connection_needs_reconnect_and_rethrows(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $connection = GoogleCalendarConnection::factory()->expiredToken()->create();

        try {
            $this->service()->accessTokenFor($connection);
            $this->fail('GoogleAuthException が投げられませんでした。');
        } catch (GoogleAuthException) {
            // 期待どおり
        }

        $this->assertSame(GoogleCalendarConnectionStatus::NeedsReconnect, $connection->fresh()->status);
    }

    public function test_force_refresh_updates_token_even_when_not_expired(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'force-refreshed', 'expires_in' => 3600]),
        ]);

        // 期限内（1時間後）でも forceRefresh は refresh_token で強制更新する
        $connection = GoogleCalendarConnection::factory()->create([
            'access_token' => 'still-valid',
            'refresh_token' => 'stored-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->assertSame('force-refreshed', $this->service()->forceRefresh($connection));
        $this->assertSame('force-refreshed', $connection->fresh()->access_token);
    }

    public function test_force_refresh_marks_needs_reconnect_on_invalid_grant(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $connection = GoogleCalendarConnection::factory()->create([
            'token_expires_at' => now()->addHour(),
        ]);

        $this->expectException(GoogleAuthException::class);

        try {
            $this->service()->forceRefresh($connection);
        } finally {
            $this->assertSame(GoogleCalendarConnectionStatus::NeedsReconnect, $connection->fresh()->status);
        }
    }

    /**
     * 401 → refresh → 新トークンで1回だけ再試行して成功する（要件のエラー表 L194）。
     */
    public function test_run_with_auth_retry_refreshes_and_retries_once_on_401(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed-token', 'expires_in' => 3600]),
            'www.googleapis.com/*' => Http::sequence()
                ->push(['error' => ['code' => 401]], 401)
                ->push(['id' => 'evt-1'], 200),
        ]);

        $connection = GoogleCalendarConnection::factory()->create([
            'access_token' => 'expired-but-not-yet-refreshed',
            'refresh_token' => 'stored-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $client = app(GoogleClient::class);

        $result = $this->service()->runWithAuthRetry(
            $connection,
            fn (string $token) => $client->insertEvent($token, 'primary', ['summary' => 'x']),
        );

        $this->assertSame('evt-1', $result['id']);
        // refresh が走り、再試行は新トークンで送られる
        $this->assertSame('refreshed-token', $connection->fresh()->access_token);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/events')
            && $request->hasHeader('Authorization', 'Bearer refreshed-token'));
    }

    /**
     * 401 → refresh が invalid_grant で失敗 → GoogleAuthException を伝播し接続は needs_reconnect。
     */
    public function test_run_with_auth_retry_propagates_auth_exception_when_refresh_fails(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
            'www.googleapis.com/*' => Http::response(['error' => ['code' => 401]], 401),
        ]);

        $connection = GoogleCalendarConnection::factory()->create([
            'access_token' => 'stored-access-token',
            'refresh_token' => 'revoked-refresh-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $client = app(GoogleClient::class);

        try {
            $this->service()->runWithAuthRetry(
                $connection,
                fn (string $token) => $client->insertEvent($token, 'primary', ['summary' => 'x']),
            );
            $this->fail('GoogleAuthException が投げられませんでした。');
        } catch (GoogleAuthException) {
            // 期待どおり
        }

        $this->assertSame(GoogleCalendarConnectionStatus::NeedsReconnect, $connection->fresh()->status);
    }

    public function test_run_with_auth_retry_returns_result_without_refresh_on_success(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.googleapis.com/*' => Http::response(['id' => 'evt-1'], 200)]);

        $connection = GoogleCalendarConnection::factory()->create([
            'access_token' => 'valid-access-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $client = app(GoogleClient::class);

        $result = $this->service()->runWithAuthRetry(
            $connection,
            fn (string $token) => $client->insertEvent($token, 'primary', ['summary' => 'x']),
        );

        $this->assertSame('evt-1', $result['id']);
        // 401 が起きていないので refresh は走らない
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com/token'));
    }

    /**
     * 401 以外のエラーは refresh せずそのまま伝播する。
     */
    public function test_run_with_auth_retry_propagates_non_401_errors_without_refresh(): void
    {
        Http::preventStrayRequests();
        Http::fake(['www.googleapis.com/*' => Http::response(['error' => ['code' => 500]], 500)]);

        $connection = GoogleCalendarConnection::factory()->create([
            'access_token' => 'valid-access-token',
            'token_expires_at' => now()->addHour(),
        ]);

        $client = app(GoogleClient::class);

        try {
            $this->service()->runWithAuthRetry(
                $connection,
                fn (string $token) => $client->insertEvent($token, 'primary', ['summary' => 'x']),
            );
            $this->fail('GoogleApiException が投げられませんでした。');
        } catch (GoogleApiException $e) {
            $this->assertSame(500, $e->status);
        }

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'oauth2.googleapis.com/token'));
    }

    private function service(): GoogleTokenService
    {
        return app(GoogleTokenService::class);
    }
}
