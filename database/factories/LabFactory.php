<?php

namespace Database\Factories;

use App\Models\Lab;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lab>
 */
class LabFactory extends Factory
{
    protected $model = Lab::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->company(),
            'phone' => fake()->numerify('01#########'),
            'contact_person' => fake()->optional()->name(),
            'address' => fake()->optional()->address(),
            'default_turnaround_days' => fake()->optional()->numberBetween(1, 21),
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
