<?php

namespace Tests\Feature\Http\Controllers;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryItemControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role): User
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_index_returns_only_active_items(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        InventoryItem::factory()->create(['is_active' => true]);
        InventoryItem::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/v1/inventory-items')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_index_filters_low_stock_items(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        InventoryItem::factory()->create([
            'is_active' => true, 'current_quantity' => 5, 'minimum_threshold' => 10,
        ]);
        InventoryItem::factory()->create([
            'is_active' => true, 'current_quantity' => 50, 'minimum_threshold' => 10,
        ]);

        $response = $this->getJson('/api/v1/inventory-items?low_stock=1')->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_store_is_forbidden_for_non_owner(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Gloves',
            'unit' => 'box',
        ])->assertStatus(403);
    }

    public function test_owner_can_create_an_item(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Gloves',
            'unit' => 'box',
            'minimum_threshold' => 5,
        ])->assertCreated()->assertJsonPath('data.name', 'Gloves');
    }

    public function test_store_rejects_duplicate_name_within_same_tenant(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        InventoryItem::factory()->create(['name' => 'Gloves']);

        $this->postJson('/api/v1/inventory-items', [
            'name' => 'Gloves',
            'unit' => 'box',
        ])->assertStatus(422)->assertJsonValidationErrors(['name']);
    }

    public function test_show_returns_404_for_an_item_belonging_to_another_tenant(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $otherTenant = Tenant::factory()->create();
        $currentTenantId = app(CurrentTenant::class)->id();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignItem = InventoryItem::factory()->create();
        app(CurrentTenant::class)->set($currentTenantId);

        $this->getJson("/api/v1/inventory-items/{$foreignItem->id}")->assertStatus(404);
    }

    public function test_owner_can_update_an_item_without_changing_its_name(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $item = InventoryItem::factory()->create(['name' => 'Gauze', 'unit' => 'pack']);

        $this->putJson("/api/v1/inventory-items/{$item->id}", [
            'name' => 'Gauze',
            'unit' => 'box',
        ])->assertOk()->assertJsonPath('data.unit', 'box');
    }

    public function test_doctor_cannot_update_an_item(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $item = InventoryItem::factory()->create();

        $this->putJson("/api/v1/inventory-items/{$item->id}", ['unit' => 'box'])
            ->assertStatus(403);
    }

    public function test_owner_can_deactivate_and_reactivate_an_item(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $item = InventoryItem::factory()->create(['is_active' => true]);

        $this->deleteJson("/api/v1/inventory-items/{$item->id}")->assertOk();
        $this->assertFalse($item->fresh()->is_active);

        $this->patchJson("/api/v1/inventory-items/{$item->id}/restore")->assertOk();
        $this->assertTrue($item->fresh()->is_active);
    }

    public function test_doctor_cannot_deactivate_an_item(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $item = InventoryItem::factory()->create();

        $this->deleteJson("/api/v1/inventory-items/{$item->id}")->assertStatus(403);
    }
}
