<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Customer;
use App\Models\Record;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Record>
 */
class RecordFactory extends Factory
{
    protected $model = Record::class;

    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'customer_id' => Customer::factory(),
            'user_id' => User::factory(),
            'status' => RecordStatus::Completed,
            'visited_at' => now(),
            'ai_summary' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn () => ['status' => RecordStatus::Draft]);
    }
}
