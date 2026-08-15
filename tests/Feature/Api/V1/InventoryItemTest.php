<?php

namespace Tests\Feature\Api\V1;

use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryItemTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $receptionist;

    private User $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'owner',
        ]);

        $this->receptionist = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'receptionist',
        ]);

        $this->doctor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'doctor',
        ]);
    }

    public function test_owner_can_create_inventory_item(): void
    {
        $payload = [
            'name' => 'Disposable Gloves',
            'unit' => 'box',
            'minimum_threshold' => 5,
        ];

        $response = $this->actingAs($this->owner)->postJson('/api/v1/inventory-items', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Disposable Gloves')
            ->assertJsonPath('data.unit', 'box');

        $this->assertDatabaseHas('inventory_items', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Disposable Gloves',
        ]);
    }

    public function test_receptionist_cannot_create_inventory_item(): void
    {
        $payload = [
            'name' => 'Cotton Rolls',
            'unit' => 'pack',
        ];

        $response = $this->actingAs($this->receptionist)->postJson('/api/v1/inventory-items', $payload);

        $response->assertStatus(403);
    }

    public function test_doctor_cannot_create_inventory_item(): void
    {
        $payload = [
            'name' => 'Cotton Rolls',
            'unit' => 'pack',
        ];

        $response = $this->actingAs($this->doctor)->postJson('/api/v1/inventory-items', $payload);

        $response->assertStatus(403);
    }

    public function test_all_roles_can_list_inventory_items(): void
    {
        InventoryItem::factory()->count(3)->create(['tenant_id' => $this->tenant->id]);

        foreach ([$this->owner, $this->receptionist, $this->doctor] as $user) {
            $response = $this->actingAs($user)->getJson('/api/v1/inventory-items');

            $response->assertStatus(200)
                ->assertJsonPath('status', 'success')
                ->assertJsonCount(3, 'data');
        }
    }

    public function test_low_stock_filter_returns_only_low_stock_items(): void
    {
        InventoryItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'current_quantity' => '5.00',
            'minimum_threshold' => '10.00',
        ]);

        InventoryItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'current_quantity' => '50.00',
            'minimum_threshold' => '10.00',
        ]);

        $response = $this->actingAs($this->owner)->getJson('/api/v1/inventory-items?low_stock=true');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_tenant_isolation_prevents_cross_tenant_access(): void
    {
        $otherTenant = Tenant::factory()->create();
        $otherOwner = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role' => 'owner',
        ]);

        InventoryItem::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'My Item']);
        InventoryItem::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Other Item']);

        // User from tenant A should only see tenant A's items
        $response = $this->actingAs($this->owner)->getJson('/api/v1/inventory-items');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My Item');

        // User from tenant B should only see tenant B's items
        $response = $this->actingAs($otherOwner)->getJson('/api/v1/inventory-items');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Other Item');
    }

    public function test_owner_can_deactivate_inventory_item(): void
    {
        $item = InventoryItem::factory()->create(['tenant_id' => $this->tenant->id]);

        $response = $this->actingAs($this->owner)->deleteJson("/api/v1/inventory-items/{$item->id}");

        $response->assertStatus(200);

        $this->assertDatabaseHas('inventory_items', [
            'id' => $item->id,
            'is_active' => false,
        ]);
    }
}
