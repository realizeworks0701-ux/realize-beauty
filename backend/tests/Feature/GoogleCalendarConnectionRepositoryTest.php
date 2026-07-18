<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Models\GoogleCalendarConnection;
use App\Models\Salon;
use App\Models\User;
use App\Repositories\GoogleCalendarConnectionRepository;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleCalendarConnectionRepositoryTest extends TestCase
{
    use RefreshDatabase;

    private GoogleCalendarConnectionRepository $repository;

    private Salon $salon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new GoogleCalendarConnectionRepository;
        $this->salon = Salon::factory()->create();
    }

    public function test_find_by_salon_and_user_is_scoped_to_the_salon(): void
    {
        $otherSalon = Salon::factory()->create();
        $user = User::factory()->create(['salon_id' => $otherSalon->id]);
        GoogleCalendarConnection::factory()->create(['salon_id' => $otherSalon->id, 'user_id' => $user->id]);

        $this->assertNull($this->repository->findBySalonAndUser($this->salon->id, $user->id));
        $this->assertNotNull($this->repository->findBySalonAndUser($otherSalon->id, $user->id));
    }

    public function test_find_shared_by_salon_ignores_per_staff_connections(): void
    {
        $user = User::factory()->create(['salon_id' => $this->salon->id]);
        GoogleCalendarConnection::factory()->create(['salon_id' => $this->salon->id, 'user_id' => $user->id]);

        $this->assertNull($this->repository->findSharedBySalon($this->salon->id));

        $shared = GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $this->salon->id]);

        $this->assertSame($shared->id, $this->repository->findSharedBySalon($this->salon->id)?->id);
    }

    public function test_find_by_salon_and_id_is_scoped_to_the_salon(): void
    {
        $otherSalon = Salon::factory()->create();
        $connection = GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $otherSalon->id]);

        $this->assertNull($this->repository->findBySalonAndId($this->salon->id, $connection->id));
        $this->assertSame($connection->id, $this->repository->findBySalonAndId($otherSalon->id, $connection->id)?->id);
    }

    public function test_find_by_channel_id_resolves_the_connection(): void
    {
        $connection = GoogleCalendarConnection::factory()->shared()->create([
            'salon_id' => $this->salon->id,
            'channel_id' => 'channel-abc',
        ]);

        $this->assertSame($connection->id, $this->repository->findByChannelId('channel-abc')?->id);
        $this->assertNull($this->repository->findByChannelId('channel-unknown'));
    }

    public function test_list_by_salon_excludes_other_salons(): void
    {
        $otherSalon = Salon::factory()->create();
        GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $otherSalon->id]);
        GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $this->salon->id]);

        $connections = $this->repository->listBySalon($this->salon->id);

        $this->assertCount(1, $connections);
        $this->assertSame($this->salon->id, $connections->first()->salon_id);
    }

    public function test_list_active_excludes_needs_reconnect(): void
    {
        GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $this->salon->id]);
        GoogleCalendarConnection::factory()->needsReconnect()->create([
            'salon_id' => $this->salon->id,
            'user_id' => User::factory()->create(['salon_id' => $this->salon->id])->id,
        ]);

        $this->assertCount(1, $this->repository->listActive());
    }

    /**
     * JST の Carbon を渡しても timestamptz と正しく比較されること（UTC 変換を欠くと9時間ずれる）。
     */
    public function test_list_expiring_channels_returns_only_channels_expiring_before_the_threshold(): void
    {
        $expiring = GoogleCalendarConnection::factory()->shared()->create([
            'salon_id' => $this->salon->id,
            'channel_expires_at' => Carbon::parse('2026-07-20 12:00', 'Asia/Tokyo'),
        ]);
        GoogleCalendarConnection::factory()->create([
            'salon_id' => $this->salon->id,
            'user_id' => User::factory()->create(['salon_id' => $this->salon->id])->id,
            'channel_expires_at' => Carbon::parse('2026-07-25 12:00', 'Asia/Tokyo'),
        ]);
        // 停止済み接続は張り直しの対象外
        GoogleCalendarConnection::factory()->needsReconnect()->create([
            'salon_id' => $this->salon->id,
            'user_id' => User::factory()->create(['salon_id' => $this->salon->id])->id,
            'channel_expires_at' => Carbon::parse('2026-07-20 12:00', 'Asia/Tokyo'),
        ]);

        $connections = $this->repository->listExpiringChannels(Carbon::parse('2026-07-21 00:00', 'Asia/Tokyo'));

        $this->assertCount(1, $connections);
        $this->assertSame($expiring->id, $connections->first()->id);
    }

    public function test_mark_needs_reconnect_updates_status(): void
    {
        $connection = GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $this->salon->id]);

        $this->repository->markNeedsReconnect($connection);

        $this->assertSame(GoogleCalendarConnectionStatus::NeedsReconnect, $connection->fresh()->status);
    }

    public function test_update_sync_token_persists_token_and_touches_last_synced_at(): void
    {
        $connection = GoogleCalendarConnection::factory()->shared()->create([
            'salon_id' => $this->salon->id,
            'sync_token' => null,
            'last_synced_at' => null,
        ]);

        $this->repository->updateSyncToken($connection, 'sync-token-1');

        $this->assertSame('sync-token-1', $connection->fresh()->sync_token);
        $this->assertNotNull($connection->fresh()->last_synced_at);

        $this->repository->clearSyncToken($connection);

        $this->assertNull($connection->fresh()->sync_token);
    }
}
