<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Salon;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'name' => fake()->name(),
            'kana' => 'テスト カナ',
            'gender' => fake()->randomElement([0, 1, 2, 9]),
            'birthday' => fake()->optional()->date(),
            'phone' => fake()->optional()->numerify('090-####-####'),
            'email' => fake()->optional()->safeEmail(),
            'memo' => fake()->optional()->sentence(),
        ];
    }
}
