<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'full_name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'date_of_birth' => fake()->date(),
            'gender' => fake()->randomElement(['male', 'female']),
            'notes' => fake()->sentence(),
        ];
    }
}
