<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryItemLowStockScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
    }

    public function test_low_stock_scope_returns_only_items_at_or_below_threshold(): void
    {
        $atThreshold = InventoryItem::factory()
            ->withQuantity('10.00')
            ->create(['minimum_threshold' => 10]);

        $aboveThreshold = InventoryItem::factory()
            ->withQuantity('25.00')
            ->create(['minimum_threshold' => 10]);

        $noThresholdSet = InventoryItem::factory()
            ->withQuantity('1.00')
            ->create(['minimum_threshold' => null]);

        $result = InventoryItem::query()->lowStock()->get();

        $this->assertTrue($result->contains($atThreshold));
        $this->assertFalse($result->contains($aboveThreshold));
        $this->assertFalse($result->contains($noThresholdSet));
    }

    public function test_low_stock_filter_on_index_endpoint_excludes_inactive_items(): void
    {
        $inactiveLowStock = InventoryItem::factory()
            ->inactive()
            ->withQuantity('1.00')
            ->create(['minimum_threshold' => 10]);

        $result = InventoryItem::query()->where('is_active', true)->lowStock()->get();

        $this->assertFalse($result->contains($inactiveLowStock));
    }
}
