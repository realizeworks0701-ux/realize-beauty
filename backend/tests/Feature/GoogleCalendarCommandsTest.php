<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Jobs\SyncGoogleCalendarJob;
use App\Models\GoogleCalendarConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleCalendarCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_renew_channels_watches_new_then_stops_old(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/events/watch')) {
                return Http::response(['id' => 'ignored', 'resourceId' => 'new-resource', 'expiration' => '1783036800000']);
            }

            return Http::response('', 204); // channels/stop
        });

        $connection = $this->expiringConnection();

        $this->artisan('google-calendar:renew-channels')->assertSuccessful();

        $fresh = $connection->fresh();
        $this->assertNotSame('old-channel', $fresh->channel_id);
        $this->assertSame('new-resource', $fresh->channel_resource_id);
        $this->assertNotNull($fresh->channel_expires_at);

        // watch → stop の順（逆順では無通知の窓が空く）
        Http::assertSentInOrder([
            fn (Request $request) => str_contains($request->url(), '/events/watch') && $request['id'] === $fresh->channel_id,
            fn (Request $request) => str_contains($request->url(), '/channels/stop')
                && $request['id'] === 'old-channel'
                && $request['resourceId'] === 'old-resource',
        ]);
    }

    public function test_renew_channels_keeps_old_channel_when_watch_fails(): void
    {
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/events/watch')) {
                return Http::response(['error' => ['code' => 500]], 500);
            }

            return Http::response('', 204);
        });

        $connection = $this->expiringConnection();

        $this->artisan('google-calendar:renew-channels')->assertSuccessful();

        // watch 失敗時はレコードを更新せず旧チャネルを維持する（次回リトライ）
        $this->assertSame('old-channel', $connection->fresh()->channel_id);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/channels/stop'));
    }

    public function test_renew_channels_ignores_non_expiring_channels(): void
    {
        Http::fake();

        $connection = GoogleCalendarConnection::factory()->create([
            'channel_id' => 'fresh-channel',
            'channel_resource_id' => 'fresh-resource',
            'channel_expires_at' => now()->addDays(5), // 24時間以内ではない
            'status' => GoogleCalendarConnectionStatus::Active,
            'token_expires_at' => now()->addHour(),
        ]);

        $this->artisan('google-calendar:renew-channels')->assertSuccessful();

        $this->assertSame('fresh-channel', $connection->fresh()->channel_id);
        Http::assertNothingSent();
    }

    public function test_refresh_sync_clears_sync_token_and_dispatches_full_sync(): void
    {
        Queue::fake();

        $active1 = GoogleCalendarConnection::factory()->create([
            'status' => GoogleCalendarConnectionStatus::Active,
            'sync_token' => 'tok-1',
        ]);
        $active2 = GoogleCalendarConnection::factory()->create([
            'status' => GoogleCalendarConnectionStatus::Active,
            'sync_token' => 'tok-2',
        ]);
        $needsReconnect = GoogleCalendarConnection::factory()->needsReconnect()->create([
            'sync_token' => 'tok-3',
        ]);

        $this->artisan('google-calendar:refresh-sync')->assertSuccessful();

        // active な接続は syncToken を破棄し全同期ジョブを投入する
        $this->assertNull($active1->fresh()->sync_token);
        $this->assertNull($active2->fresh()->sync_token);
        // needs_reconnect は対象外
        $this->assertSame('tok-3', $needsReconnect->fresh()->sync_token);

        Queue::assertPushed(SyncGoogleCalendarJob::class, 2);
        Queue::assertPushed(fn (SyncGoogleCalendarJob $job) => $job->connectionId === $active1->id);
    }

    private function expiringConnection(): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::factory()->create([
            'channel_id' => 'old-channel',
            'channel_resource_id' => 'old-resource',
            'channel_token' => 'old-token',
            'channel_expires_at' => now()->addHours(12), // 24時間以内 = 張り直し対象
            'status' => GoogleCalendarConnectionStatus::Active,
            'token_expires_at' => now()->addHour(),
        ]);
    }
}
