<?php

namespace Database\Factories;

use App\Models\Service;
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
            'name_ar' => fake()->word(),
            'name_en' => fake()->word(),
            'default_price' => fake()->randomFloat(2, 50, 500),
            'is_active' => true,
            'is_other' => false,
        ];
    }

    public function other(): static
    {
        return $this->state(fn () => ['is_other' => true]);
    }
}
