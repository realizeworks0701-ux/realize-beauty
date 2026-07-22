<?php

namespace Database\Factories;

use App\Models\GoogleBusyBlock;
use App\Models\GoogleCalendarConnection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<GoogleBusyBlock>
 */
class GoogleBusyBlockFactory extends Factory
{
    protected $model = GoogleBusyBlock::class;

    public function definition(): array
    {
        $connection = GoogleCalendarConnection::factory();

        return [
            'google_calendar_connection_id' => $connection,
            'salon_id' => fn (array $attributes) => GoogleCalendarConnection::find($attributes['google_calendar_connection_id'])->salon_id,
            'user_id' => fn (array $attributes) => GoogleCalendarConnection::find($attributes['google_calendar_connection_id'])->user_id,
            'google_event_id' => Str::random(26),
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHour(),
        ];
    }

    /**
     * 接続に紐づけ、salon_id / user_id を接続から引き継ぐ。
     */
    public function forConnection(GoogleCalendarConnection $connection): static
    {
        return $this->state(fn () => [
            'google_calendar_connection_id' => $connection->id,
            'salon_id' => $connection->salon_id,
            'user_id' => $connection->user_id,
        ]);
    }
}
