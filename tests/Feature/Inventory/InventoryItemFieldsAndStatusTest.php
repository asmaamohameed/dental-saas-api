<?php

namespace Tests\Feature\Inventory;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryItemFieldsAndStatusTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => $role,
        ]);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_store_requires_cost_price_and_rejects_negative(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Probe tips',
            'unit' => 'pack',
        ])->assertStatus(422)->assertJsonValidationErrors(['cost_price']);

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Probe tips',
            'unit' => 'pack',
            'cost_price' => -1,
        ])->assertStatus(422)->assertJsonValidationErrors(['cost_price']);
    }

    public function test_store_accepts_optional_expiry_and_supplier(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Bonding agent',
            'unit' => 'bottle',
            'cost_price' => 45.75,
            'expiry_date' => '2027-06-01',
            'supplier' => 'Acme Dental',
        ])->assertCreated()
            ->assertJsonPath('data.cost_price', 45.75)
            ->assertJsonPath('data.expiry_date', '2027-06-01')
            ->assertJsonPath('data.supplier', 'Acme Dental');

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Cotton rolls',
            'unit' => 'bag',
            'cost_price' => 8,
        ])->assertCreated()
            ->assertJsonPath('data.expiry_date', null)
            ->assertJsonPath('data.supplier', null);
    }

    public function test_update_cost_expiry_and_supplier_and_null_clears_optional_fields(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $item = InventoryItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'cost_price' => '10.00',
            'expiry_date' => '2026-12-01',
            'supplier' => 'Old Supplier',
        ]);

        $this->patchJson("/api/v1/inventory-items/{$item->id}", [
            'cost_price' => 15.5,
            'expiry_date' => '2027-01-15',
            'supplier' => 'New Supplier',
        ])->assertOk()
            ->assertJsonPath('data.cost_price', 15.5)
            ->assertJsonPath('data.expiry_date', '2027-01-15')
            ->assertJsonPath('data.supplier', 'New Supplier');

        $this->patchJson("/api/v1/inventory-items/{$item->id}", [
            'expiry_date' => null,
            'supplier' => null,
        ])->assertOk()
            ->assertJsonPath('data.expiry_date', null)
            ->assertJsonPath('data.supplier', null);

        $this->patchJson("/api/v1/inventory-items/{$item->id}", [
            'cost_price' => null,
        ])->assertStatus(422)->assertJsonValidationErrors(['cost_price']);
    }

    public function test_owner_can_list_inactive_and_all_items(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        InventoryItem::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        InventoryItem::factory()->inactive()->create(['tenant_id' => $this->tenant->id]);

        $this->getJson('/api/v1/inventory-items?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.is_active', false);

        $this->getJson('/api/v1/inventory-items?status=all')
            ->assertOk()
            ->assertJsonCount(2, 'data.items');
    }

    #[DataProvider('nonOwnerRolesProvider')]
    public function test_non_owner_cannot_list_inactive_or_all(UserRole $role): void
    {
        $this->actingAsRole($role);

        $this->getJson('/api/v1/inventory-items?status=inactive')->assertForbidden();
        $this->getJson('/api/v1/inventory-items?status=all')->assertForbidden();
    }

    public static function nonOwnerRolesProvider(): array
    {
        return [
            'doctor' => [UserRole::DOCTOR],
            'receptionist' => [UserRole::RECEPTIONIST],
            'assistant' => [UserRole::ASSISTANT],
        ];
    }

    public function test_invalid_status_returns_422(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $this->getJson('/api/v1/inventory-items?status=archived')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_default_list_returns_active_only(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        InventoryItem::factory()->create(['tenant_id' => $this->tenant->id, 'is_active' => true]);
        InventoryItem::factory()->inactive()->create(['tenant_id' => $this->tenant->id]);

        $this->getJson('/api/v1/inventory-items')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.is_active', true);
    }

    public function test_low_stock_ignores_inactive_items_even_when_status_is_all(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        InventoryItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'current_quantity' => 2,
            'minimum_threshold' => 10,
        ]);
        InventoryItem::factory()->inactive()->create([
            'tenant_id' => $this->tenant->id,
            'current_quantity' => 1,
            'minimum_threshold' => 10,
        ]);
        InventoryItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'is_active' => true,
            'current_quantity' => 50,
            'minimum_threshold' => 10,
        ]);

        $this->getJson('/api/v1/inventory-items?status=all&low_stock=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.is_active', true);
    }

    public function test_restore_flow_preserves_transaction_history(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $item = InventoryItem::factory()->withQuantity('12.00')->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Sutures',
        ]);

        InventoryTransaction::factory()->create([
            'tenant_id' => $this->tenant->id,
            'inventory_item_id' => $item->id,
            'quantity' => '12.00',
        ]);

        $this->deleteJson("/api/v1/inventory-items/{$item->id}")->assertOk();

        $inactiveList = $this->getJson('/api/v1/inventory-items?status=inactive')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $inactiveList);
        $this->assertSame($item->id, $inactiveList[0]['id']);

        $this->patchJson("/api/v1/inventory-items/{$item->id}/restore")->assertOk();

        $this->getJson('/api/v1/inventory-items')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $item->id);

        $this->assertSame(1, InventoryTransaction::query()->where('inventory_item_id', $item->id)->count());
    }

    public function test_inactive_list_is_tenant_scoped(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        InventoryItem::factory()->inactive()->create(['tenant_id' => $this->tenant->id]);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        InventoryItem::factory()->inactive()->create(['tenant_id' => $otherTenant->id]);
        app(CurrentTenant::class)->set($this->tenant->id);

        $this->getJson('/api/v1/inventory-items?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }
}
