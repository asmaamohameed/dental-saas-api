<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Dental Clinic',
            'subdomain' => fake()->unique()->slug(),
            'locale' => 'ar',
            'status' => 'active',
        ];
    }
}
