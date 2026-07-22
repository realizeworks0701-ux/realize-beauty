<?php

namespace Database\Factories;

use App\Enums\GoogleCalendarConnectionStatus;
use App\Models\GoogleCalendarConnection;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleCalendarConnection>
 */
class GoogleCalendarConnectionFactory extends Factory
{
    protected $model = GoogleCalendarConnection::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'user_id' => User::factory(),
            'google_account_email' => fake()->unique()->safeEmail(),
            'calendar_id' => 'primary',
            'access_token' => 'ya29.'.Str::random(40),
            'refresh_token' => '1//'.Str::random(40),
            'token_expires_at' => now()->addHour(),
            'sync_token' => null,
            'channel_id' => (string) Str::uuid(),
            'channel_resource_id' => Str::random(20),
            'channel_token' => Str::random(32),
            'channel_expires_at' => now()->addDays(7),
            'last_synced_at' => null,
            'status' => GoogleCalendarConnectionStatus::Active,
        ];
    }

    /**
     * サロン共有接続（shared モード）。
     */
    public function shared(): static
    {
        return $this->state(fn () => ['user_id' => null]);
    }

    public function needsReconnect(): static
    {
        return $this->state(fn () => ['status' => GoogleCalendarConnectionStatus::NeedsReconnect]);
    }

    public function expiredToken(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subMinute()]);
    }
}
