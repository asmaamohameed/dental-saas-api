<?php

namespace Tests\Feature\Inventory;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryItemReactivationTest extends TestCase
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

    public function test_owner_can_deactivate_then_reactivate_item_instead_of_creating_duplicate(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $item = InventoryItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Dental Floss',
        ]);

        // Deactivate (soft) via destroy endpoint.
        $this->deleteJson("/api/v1/inventory-items/{$item->id}")->assertOk();

        $item->refresh();
        $this->assertFalse($item->is_active);

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Dental Floss',
            'unit' => 'box',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['name']);

        $this->assertDatabaseCount('inventory_items', 1);

        $this->patchJson("/api/v1/inventory-items/{$item->id}/restore")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $item->refresh();
        $this->assertTrue($item->is_active);
    }

    public function test_doctor_cannot_create_inventory_item(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Gauze Pads',
            'unit' => 'pack',
        ])->assertForbidden();
    }

    public function test_receptionist_cannot_create_inventory_item(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Gauze Pads',
            'unit' => 'pack',
        ])->assertForbidden();
    }

    public function test_doctor_cannot_restore_inventory_item(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $item = InventoryItem::factory()->inactive()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->actingAsRole(UserRole::DOCTOR);

        $this->patchJson("/api/v1/inventory-items/{$item->id}/restore")
            ->assertForbidden();

        $item->refresh();
        $this->assertFalse($item->is_active);
    }

    public function test_doctor_and_receptionist_can_view_inventory_items(): void
    {
        $item = InventoryItem::factory()->create(['tenant_id' => $this->tenant->id]);

        foreach ([UserRole::DOCTOR, UserRole::RECEPTIONIST] as $role) {
            $this->actingAsRole($role);

            $this->getJson("/api/v1/inventory-items/{$item->id}")
                ->assertOk()
                ->assertJsonPath('data.id', $item->id);
        }
    }
}
