<?php

namespace Database\Factories;

use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransaction>
 */
class InventoryTransactionFactory extends Factory
{
    protected $model = InventoryTransaction::class;

    public function definition(): array
    {
        return [
            'inventory_item_id' => InventoryItem::factory(),
            'tenant_id' => function (array $attributes) {
                if (isset($attributes['inventory_item_id'])) {
                    $item = $attributes['inventory_item_id'] instanceof InventoryItem
                        ? $attributes['inventory_item_id']
                        : InventoryItem::find($attributes['inventory_item_id']);

                    if ($item) {
                        return $item->tenant_id;
                    }
                }

                return Tenant::factory();
            },
            'type' => 'in',
            'quantity' => '10.00',
            'reason' => 'purchase',
            'performed_by' => function (array $attributes) {
                return User::factory()->create([
                    'tenant_id' => $attributes['tenant_id'],
                ]);
            },
        ];
    }
}
