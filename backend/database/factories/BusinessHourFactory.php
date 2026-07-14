<?php

namespace Database\Factories;

use App\Models\BusinessHour;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessHour>
 */
class BusinessHourFactory extends Factory
{
    protected $model = BusinessHour::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'is_closed' => false,
            'open_time' => '09:00',
            'close_time' => '19:00',
        ];
    }

    public function closed(): static
    {
        return $this->state(fn () => ['is_closed' => true]);
    }
}
