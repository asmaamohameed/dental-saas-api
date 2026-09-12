<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryTransactionType;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InventoryTransactionService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryTransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private InventoryTransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        app(CurrentTenant::class)->set($this->tenant->id);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->service = app(InventoryTransactionService::class);
    }

    public function test_in_transaction_increases_current_quantity(): void
    {
        $item = InventoryItem::factory()->withQuantity('10.00')->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $transaction = $this->service->create($item, [
            'type' => 'in',
            'quantity' => '5.00',
            'reason' => 'restock',
        ], $this->user->id);

        $item->refresh();

        $this->assertSame('15.00', $item->current_quantity);
        $this->assertSame(InventoryTransactionType::IN, $transaction->type);
        $this->assertSame($this->user->id, $transaction->performed_by);
    }

    public function test_out_transaction_decreases_current_quantity(): void
    {
        $item = InventoryItem::factory()->withQuantity('10.00')->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->service->create($item, [
            'type' => 'out',
            'quantity' => '4.00',
            'reason' => 'usage',
        ], $this->user->id);

        $item->refresh();

        $this->assertSame('6.00', $item->current_quantity);
    }

    public function test_out_transaction_fails_when_insufficient_stock_and_leaves_quantity_unchanged(): void
    {
        $item = InventoryItem::factory()->withQuantity('3.00')->create([
            'tenant_id' => $this->tenant->id,
        ]);

        try {
            $this->service->create($item, [
                'type' => 'out',
                'quantity' => '5.00',
            ], $this->user->id);

            $this->fail('Expected a ValidationException for insufficient stock.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }

        $item->refresh();
        $this->assertSame('3.00', $item->current_quantity);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_transaction_rejected_for_inactive_item(): void
    {
        $item = InventoryItem::factory()->inactive()->withQuantity('10.00')->create([
            'tenant_id' => $this->tenant->id,
        ]);

        try {
            $this->service->create($item, [
                'type' => 'in',
                'quantity' => '1.00',
            ], $this->user->id);

            $this->fail('Expected a ValidationException for inactive item.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('inventory_item_id', $e->errors());
        }

        $item->refresh();
        $this->assertSame('10.00', $item->current_quantity);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }
}
