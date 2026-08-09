<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name_ar' => fake()->word().' خدمة أسنان',
            'name_en' => fake()->word().' Dental Service',
            'default_price' => fake()->randomFloat(2, 50, 1000),
            'is_active' => true,
            'is_other' => false,
        ];
    }

    public function other(): static
    {
        return $this->state(fn (array $attributes) => [
            'name_ar' => 'أخرى',
            'name_en' => 'Other',
            'is_other' => true,
            'default_price' => 0,
        ]);
    }
}
