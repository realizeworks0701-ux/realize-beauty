<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Salon;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'salon_id' => Salon::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => Role::Owner,
            'is_active' => true,
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }
}
