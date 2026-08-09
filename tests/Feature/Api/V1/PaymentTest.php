<!-- <?php

// namespace Tests\Feature\Api\V1;

// use App\Models\Invoice;
// use App\Models\InvoiceItem;
// use App\Models\Patient;
// use App\Models\Payment;
// use App\Models\Service;
// use App\Models\Tenant;
// use App\Models\User;
// use Illuminate\Foundation\Testing\RefreshDatabase;
// use Tests\TestCase;

//class PaymentTest extends TestCase
//{
    // use RefreshDatabase;

    // private Tenant $tenant;

    // private User $owner;

    // private User $receptionist;

    // private User $doctor;

    // private Invoice $invoice;

    // protected function setUp(): void
    // {
    //     parent::setUp();

    //     $this->tenant = Tenant::factory()->create();

    //     $this->owner = User::factory()->create([
    //         'tenant_id' => $this->tenant->id,
    //         'role' => 'owner',
    //     ]);

    //     $this->receptionist = User::factory()->create([
    //         'tenant_id' => $this->tenant->id,
    //         'role' => 'receptionist',
    //     ]);

    //     $this->doctor = User::factory()->create([
    //         'tenant_id' => $this->tenant->id,
    //         'role' => 'doctor',
    //     ]);

    //     $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
    //     $service = Service::factory()->create(['tenant_id' => $this->tenant->id]);

    //     $this->invoice = Invoice::factory()->create([
    //         'tenant_id' => $this->tenant->id,
    //         'patient_id' => $patient->id,
    //         'total_amount' => 500.00,
    //         'status' => 'unpaid',
    //     ]);

    //     InvoiceItem::factory()->create([
    //         'invoice_id' => $this->invoice->id,
    //         'service_id' => $service->id,
    //         'price' => 500.00,
    //         'quantity' => 1,
    //     ]);
    // }

    // public function test_receptionist_can_record_payment_and_update_status(): void
    // {
    //     $payload = [
    //         'amount' => 200.00,
    //         'method' => 'cash',
    //         'notes' => 'Partial payment',
    //     ];

    //     $response = $this->actingAs($this->receptionist)->postJson("/api/v1/invoices/{$this->invoice->id}/payments", $payload);

    //     $response->assertStatus(201)
    //         ->assertJsonPath('data.amount', 200);

    //     $this->assertEquals('partial', $this->invoice->fresh()->status);
    // }

    // public function test_full_payment_sets_status_to_paid(): void
    // {
    //     $payload = [
    //         'amount' => 500.00,
    //         'method' => 'card',
    //     ];

    //     $response = $this->actingAs($this->receptionist)->postJson("/api/v1/invoices/{$this->invoice->id}/payments", $payload);

    //     $response->assertStatus(201);
    //     $this->assertEquals('paid', $this->invoice->fresh()->status);
    // }

    // public function test_cannot_exceed_remaining_invoice_balance(): void
    // {
    //     $payload = [
    //         'amount' => 600.00,
    //         'method' => 'cash',
    //     ];

    //     $response = $this->actingAs($this->receptionist)->postJson("/api/v1/invoices/{$this->invoice->id}/payments", $payload);

    //     $response->assertStatus(422)
    //         ->assertJsonValidationErrors(['amount']);
    // }

    // public function test_receptionist_cannot_update_or_delete_payment(): void
    // {
    //     $payment = Payment::factory()->create([
    //         'tenant_id' => $this->tenant->id,
    //         'invoice_id' => $this->invoice->id,
    //         'amount' => 100.00,
    //         'received_by' => $this->receptionist->id,
    //     ]);

    //     $updateRes = $this->actingAs($this->receptionist)->putJson("/api/v1/invoices/{$this->invoice->id}/payments/{$payment->id}", [
    //         'amount' => 150.00,
    //         'method' => 'cash',
    //     ]);
    //     $updateRes->assertStatus(403);

    //     $delRes = $this->actingAs($this->receptionist)->deleteJson("/api/v1/invoices/{$this->invoice->id}/payments/{$payment->id}");
    //     $delRes->assertStatus(403);
    // }

    // public function test_owner_can_soft_delete_payment_and_recalculate_status(): void
    // {
    //     $payment = Payment::factory()->create([
    //         'tenant_id' => $this->tenant->id,
    //         'invoice_id' => $this->invoice->id,
    //         'amount' => 500.00,
    //         'received_by' => $this->receptionist->id,
    //     ]);

    //     $this->invoice->update(['status' => 'paid']);

    //     $delRes = $this->actingAs($this->owner)->deleteJson("/api/v1/invoices/{$this->invoice->id}/payments/{$payment->id}");
    //     $delRes->assertStatus(200);

    //     $this->assertSoftDeleted('payments', ['id' => $payment->id]);
    //     $this->assertEquals('unpaid', $this->invoice->fresh()->status);
    // }
//} -->
