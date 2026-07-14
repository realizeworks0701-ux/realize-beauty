<?php

namespace Database\Factories;

use App\Models\Menu;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
{
    protected $model = Menu::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'name' => fake()->randomElement(['カット', 'カラー', 'パーマ', 'トリートメント']),
            'price' => fake()->numberBetween(1000, 20000),
            'duration_minutes' => fake()->randomElement([30, 60, 90, 120]),
            'display_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
