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
            'tenant_id' => Tenant::factory(),
            'inventory_item_id' => InventoryItem::factory(),
            'type' => 'in',
            'quantity' => '10.00',
            'reason' => 'purchase',
            'performed_by' => User::factory(),
        ];
    }
}
