<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Models\GoogleCalendarConnection;
use App\Services\Google\GoogleAuthException;
use App\Services\Google\GoogleTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function service(): GoogleTokenService
    {
        return app(GoogleTokenService::class);
    }
}
