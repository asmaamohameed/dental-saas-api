<?php

namespace Tests\Feature\Api\V1;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceItemTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $owner;
    private User $receptionist;
    private Invoice $invoice;
    private Service $service;

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

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->service = Service::factory()->create(['tenant_id' => $this->tenant->id, 'default_price' => 100.00]);

        $this->invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'total_amount' => 100.00,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $this->invoice->id,
            'service_id' => $this->service->id,
            'price' => 100.00,
            'quantity' => 1,
        ]);
    }

    public function test_can_add_item_and_recalculate_total(): void
    {
        $payload = [
            'service_id' => $this->service->id,
            'price' => 150.00,
            'quantity' => 2,
        ];

        $response = $this->actingAs($this->receptionist)->postJson("/api/v1/invoices/{$this->invoice->id}/items", $payload);

        $response->assertStatus(201);
        $this->assertEquals(400.00, $this->invoice->fresh()->total_amount);
    }

    public function test_cannot_delete_last_item_from_invoice(): void
    {
        $item = $this->invoice->items()->first();

        $response = $this->actingAs($this->receptionist)->deleteJson("/api/v1/invoices/{$this->invoice->id}/items/{$item->id}");

        $response->assertStatus(422);
    }
}
