<?php

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\Reservation;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    protected $model = Reservation::class;

    public function definition(): array
    {
        $startAt = now()->addDay()->setTime(10, 0);

        return [
            'salon_id' => Salon::factory(),
            'customer_id' => Customer::factory(),
            'menu_id' => Menu::factory(),
            'user_id' => User::factory(),
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMinutes(60),
            'status' => ReservationStatus::Reserved,
            'note' => fake()->optional()->sentence(),
        ];
    }

    public function status(ReservationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
