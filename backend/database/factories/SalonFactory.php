<?php

namespace Database\Factories;

use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Salon>
 */
class SalonFactory extends Factory
{
    protected $model = Salon::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().'サロン',
            'phone' => fake()->numerify('03-####-####'),
            'postal_code' => fake()->numerify('###-####'),
            'address' => fake()->address(),
            'is_active' => true,
        ];
    }
}
