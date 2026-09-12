<?php

namespace Database\Factories;

use App\Enums\UserRole;
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
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'password_hash' => Hash::make('password'),
            'role' => UserRole::DOCTOR,
            'locale' => null,
            'is_active' => true,
        ];
    }

    public function owner(): static
    {
        return $this->state(fn () => ['role' => UserRole::OWNER]);
    }

    public function doctor(): static
    {
        return $this->state(fn () => ['role' => UserRole::DOCTOR]);
    }

    public function receptionist(): static
    {
        return $this->state(fn () => ['role' => UserRole::RECEPTIONIST]);
    }
}
