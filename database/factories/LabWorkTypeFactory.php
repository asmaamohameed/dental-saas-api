<?php

namespace Database\Factories;

use App\Models\LabWorkType;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LabWorkType>
 */
class LabWorkTypeFactory extends Factory
{
    protected $model = LabWorkType::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->unique()->words(2, true),
        ];
    }
}
