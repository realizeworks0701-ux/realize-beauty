<?php

namespace Database\Factories;

use App\Models\LineSetting;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LineSetting>
 */
class LineSettingFactory extends Factory
{
    protected $model = LineSetting::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'channel_id' => fake()->unique()->numerify('##########'),
            'channel_secret' => Str::random(32),
            'channel_access_token' => Str::random(64),
            'bot_user_id' => 'U'.fake()->unique()->md5(),
            'bot_basic_id' => '@'.strtolower(Str::random(7)),
            'bot_display_name' => fake()->company(),
            'is_active' => true,
            'connected_at' => now(),
            'last_webhook_at' => null,
        ];
    }

    /**
     * 認証情報の保存のみで接続確認前の状態。
     */
    public function unverified(): static
    {
        return $this->state(fn () => [
            'bot_user_id' => null,
            'bot_basic_id' => null,
            'bot_display_name' => null,
            'is_active' => false,
            'connected_at' => null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
