<?php

namespace Tests\Feature;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Enums\GoogleCalendarMode;
use App\Models\GoogleBusyBlock;
use App\Models\GoogleCalendarConnection;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GoogleCalendarConnectionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_per_staff_partial_unique_rejects_second_connection_for_same_salon_and_user(): void
    {
        $salon = Salon::factory()->create();
        $user = User::factory()->create(['salon_id' => $salon->id]);

        GoogleCalendarConnection::factory()->create(['salon_id' => $salon->id, 'user_id' => $user->id]);

        $this->expectException(QueryException::class);

        GoogleCalendarConnection::factory()->create(['salon_id' => $salon->id, 'user_id' => $user->id]);
    }

    public function test_per_staff_partial_unique_allows_different_users_in_same_salon(): void
    {
        $salon = Salon::factory()->create();
        $users = User::factory()->count(2)->create(['salon_id' => $salon->id]);

        foreach ($users as $user) {
            GoogleCalendarConnection::factory()->create(['salon_id' => $salon->id, 'user_id' => $user->id]);
        }

        $this->assertSame(2, GoogleCalendarConnection::where('salon_id', $salon->id)->count());
    }

    public function test_shared_partial_unique_rejects_second_shared_connection_for_same_salon(): void
    {
        $salon = Salon::factory()->create();

        GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $salon->id]);

        $this->expectException(QueryException::class);

        GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $salon->id]);
    }

    public function test_shared_partial_unique_allows_one_shared_connection_per_salon(): void
    {
        $salons = Salon::factory()->count(2)->create();

        foreach ($salons as $salon) {
            GoogleCalendarConnection::factory()->shared()->create(['salon_id' => $salon->id]);
        }

        $this->assertSame(2, GoogleCalendarConnection::whereNull('user_id')->count());
    }

    public function test_tokens_are_encrypted_at_rest_and_hidden_from_array(): void
    {
        $connection = GoogleCalendarConnection::factory()->create([
            'access_token' => 'plain-access-token',
            'refresh_token' => 'plain-refresh-token',
        ]);

        $raw = DB::table('google_calendar_connections')->where('id', $connection->id)->first();

        $this->assertNotSame('plain-access-token', $raw->access_token);
        $this->assertNotSame('plain-refresh-token', $raw->refresh_token);
        $this->assertStringNotContainsString('plain-access-token', $raw->access_token);
        $this->assertStringNotContainsString('plain-refresh-token', $raw->refresh_token);

        $this->assertSame('plain-access-token', Crypt::decryptString($raw->access_token));
        $this->assertSame('plain-refresh-token', Crypt::decryptString($raw->refresh_token));

        $this->assertSame('plain-access-token', $connection->fresh()->access_token);
        $this->assertSame('plain-refresh-token', $connection->fresh()->refresh_token);

        $array = $connection->fresh()->toArray();
        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
        $this->assertArrayNotHasKey('sync_token', $array);
        $this->assertArrayNotHasKey('channel_token', $array);
    }

    public function test_status_and_mode_are_cast_to_enums_with_defaults(): void
    {
        $connection = GoogleCalendarConnection::factory()->create();

        $this->assertInstanceOf(GoogleCalendarConnectionStatus::class, $connection->status);
        $this->assertSame(GoogleCalendarConnectionStatus::Active, $connection->fresh()->status);
        $this->assertSame('primary', $connection->fresh()->calendar_id);

        $salon = Salon::factory()->create(['google_calendar_mode' => GoogleCalendarMode::PerStaff]);

        $this->assertSame(GoogleCalendarMode::PerStaff, $salon->fresh()->google_calendar_mode);
        $this->assertNull(Salon::factory()->create()->fresh()->google_calendar_mode);
    }

    public function test_busy_blocks_are_cascade_deleted_with_the_connection(): void
    {
        $connection = GoogleCalendarConnection::factory()->create();
        GoogleBusyBlock::factory()->forConnection($connection)->count(2)->create();

        $connection->delete();

        $this->assertSame(0, GoogleBusyBlock::where('google_calendar_connection_id', $connection->id)->count());
    }

    public function test_busy_block_unique_rejects_duplicate_event_id_within_connection(): void
    {
        $connection = GoogleCalendarConnection::factory()->create();
        GoogleBusyBlock::factory()->forConnection($connection)->create(['google_event_id' => 'evt-1']);

        $this->expectException(QueryException::class);

        GoogleBusyBlock::factory()->forConnection($connection)->create(['google_event_id' => 'evt-1']);
    }
}
