<?php

namespace Tests\Feature\Api\V1;

use App\Models\InventoryItem;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTransactionTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    private User $receptionist;

    private User $doctor;

    private InventoryItem $item;

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

        $this->item = InventoryItem::factory()->create([
            'tenant_id' => $this->tenant->id,
            'current_quantity' => '100.00',
        ]);
    }

    public function test_owner_can_log_in_transaction(): void
    {
        $payload = [
            'inventory_item_id' => $this->item->id,
            'type' => 'in',
            'quantity' => 25,
            'reason' => 'purchase',
        ];

        $response = $this->actingAs($this->owner)->postJson(
            "/api/v1/inventory-items/{$this->item->id}/transactions",
            $payload
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'in')
            ->assertJsonPath('data.quantity', 25.0);

        $this->item->refresh();
        $this->assertEquals('125.00', $this->item->current_quantity);
    }

    public function test_owner_can_log_out_transaction(): void
    {
        $payload = [
            'inventory_item_id' => $this->item->id,
            'type' => 'out',
            'quantity' => 30,
            'reason' => 'usage',
        ];

        $response = $this->actingAs($this->owner)->postJson(
            "/api/v1/inventory-items/{$this->item->id}/transactions",
            $payload
        );

        $response->assertStatus(201)
            ->assertJsonPath('data.type', 'out')
            ->assertJsonPath('data.quantity', 30.0);

        $this->item->refresh();
        $this->assertEquals('70.00', $this->item->current_quantity);
    }

    public function test_receptionist_can_log_transaction(): void
    {
        $payload = [
            'inventory_item_id' => $this->item->id,
            'type' => 'in',
            'quantity' => 10,
            'reason' => 'purchase',
        ];

        $response = $this->actingAs($this->receptionist)->postJson(
            "/api/v1/inventory-items/{$this->item->id}/transactions",
            $payload
        );

        $response->assertStatus(201);
    }

    public function test_doctor_cannot_log_transaction(): void
    {
        $payload = [
            'inventory_item_id' => $this->item->id,
            'type' => 'in',
            'quantity' => 10,
            'reason' => 'purchase',
        ];

        $response = $this->actingAs($this->doctor)->postJson(
            "/api/v1/inventory-items/{$this->item->id}/transactions",
            $payload
        );

        $response->assertStatus(403);
    }

    public function test_out_transaction_rejected_when_insufficient_quantity(): void
    {
        $payload = [
            'inventory_item_id' => $this->item->id,
            'type' => 'out',
            'quantity' => 150,
            'reason' => 'usage',
        ];

        $response = $this->actingAs($this->owner)->postJson(
            "/api/v1/inventory-items/{$this->item->id}/transactions",
            $payload
        );

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quantity']);

        $this->item->refresh();
        $this->assertEquals('100.00', $this->item->current_quantity);
    }

    public function test_concurrent_out_transactions_reject_overdraft(): void
    {
        // Set item to exactly 5 units
        $this->item->update(['current_quantity' => '5.00']);

        $payload = [
            'inventory_item_id' => $this->item->id,
            'type' => 'out',
            'quantity' => 5,
            'reason' => 'usage',
        ];

        // Simulate two concurrent requests — both try to withdraw 5 from a balance of 5.
        // Due to lockForUpdate, they execute serially. The first succeeds (5 - 5 = 0),
        // the second must fail (0 - 5 < 0).
        $response1 = $this->actingAs($this->owner)->postJson(
            "/api/v1/inventory-items/{$this->item->id}/transactions",
            $payload
        );

        $response2 = $this->actingAs($this->owner)->postJson(
            "/api/v1/inventory-items/{$this->item->id}/transactions",
            $payload
        );

        $statuses = [$response1->status(), $response2->status()];
        sort($statuses);

        // One must succeed (201) and one must fail (422)
        $this->assertEquals([201, 422], $statuses);

        $this->item->refresh();
        $this->assertEquals('0.00', $this->item->current_quantity);
    }

    public function test_all_roles_can_list_transactions(): void
    {
        // Create a transaction first
        $this->item->transactions()->create([
            'type' => 'in',
            'quantity' => '10.00',
            'reason' => 'purchase',
            'performed_by' => $this->owner->id,
        ]);

        foreach ([$this->owner, $this->receptionist, $this->doctor] as $user) {
            $response = $this->actingAs($user)->getJson(
                "/api/v1/inventory-items/{$this->item->id}/transactions"
            );

            $response->assertStatus(200)
                ->assertJsonPath('status', 'success');
        }
    }
}
