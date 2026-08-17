<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryItem>
 */
class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->randomElement([
                'Disposable Gloves',
                'Dental Mirrors',
                'Cotton Rolls',
                'Composite Resin',
                'Anesthetic Cartridges',
                'Sterilization Pouches',
                'Dental Bibs',
                'Suction Tips',
                'Prophy Paste',
                'Fluoride Varnish',
            ]),
            'unit' => fake()->randomElement(['piece', 'box', 'tube', 'pack', 'bottle']),
            'current_quantity' => '50.00',
            'minimum_threshold' => '10.00',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
