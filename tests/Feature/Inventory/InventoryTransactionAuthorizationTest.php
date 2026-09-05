<?php

namespace Tests\Feature\Inventory;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InventoryTransactionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private InventoryItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);

        $this->item = InventoryItem::factory()->withQuantity('20.00')->create([
            'tenant_id' => $this->tenant->id,
        ]);
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

    #[DataProvider('allowedRolesProvider')]
    public function test_role_can_record_inventory_transaction(UserRole $role): void
    {
        $this->actingAsRole($role);

        $this->postJson("/api/v1/inventory-items/{$this->item->id}/transactions", [
            'inventory_item_id' => $this->item->id,
            'type' => 'in',
            'quantity' => '3.00',
            'reason' => 'restock',
        ])->assertCreated();

        $this->item->refresh();
        $this->assertSame('23.00', $this->item->current_quantity);
    }

    public static function allowedRolesProvider(): array
    {
        return [
            'owner' => [UserRole::OWNER],
            'doctor' => [UserRole::DOCTOR],
            'receptionist' => [UserRole::RECEPTIONIST],
        ];
    }

    public function test_doctor_and_receptionist_can_view_transactions_list(): void
    {
        foreach ([UserRole::DOCTOR, UserRole::RECEPTIONIST] as $role) {
            $this->actingAsRole($role);

            $this->getJson("/api/v1/inventory-items/{$this->item->id}/transactions")
                ->assertOk();
        }
    }

    public function test_transaction_rejected_when_type_is_invalid(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $this->postJson("/api/v1/inventory-items/{$this->item->id}/transactions", [
            'inventory_item_id' => $this->item->id,
            'type' => 'transfer',
            'quantity' => '3.00',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }
}
