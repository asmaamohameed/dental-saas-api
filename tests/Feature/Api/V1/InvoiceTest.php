<?php

// namespace Tests\Feature\Api\V1;

// use App\Models\Invoice;
// use App\Models\Patient;
// use App\Models\Service;
// use App\Models\Tenant;
// use App\Models\User;
// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceTest extends TestCase
{
//     use RefreshDatabase;

//     private Tenant $tenant;

//     private User $owner;

//     private User $receptionist;

//     private User $doctor;

//     private Patient $patient;

//     private Service $service;

//     protected function setUp(): void
//     {
//         parent::setUp();

//         $this->tenant = Tenant::factory()->create();

//         $this->owner = User::factory()->create([
//             'tenant_id' => $this->tenant->id,
//             'role' => 'owner',
//         ]);

//         $this->receptionist = User::factory()->create([
//             'tenant_id' => $this->tenant->id,
//             'role' => 'receptionist',
//         ]);

//         $this->doctor = User::factory()->create([
//             'tenant_id' => $this->tenant->id,
//             'role' => 'doctor',
//         ]);

//         $this->patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
//         $this->service = Service::factory()->create(['tenant_id' => $this->tenant->id, 'default_price' => 200.00]);
//     }

//     public function test_can_create_invoice_with_items(): void
//     {
//         $payload = [
//             'patient_id' => $this->patient->id,
//             'items' => [
//                 [
//                     'service_id' => $this->service->id,
//                     'price' => 200.00,
//                     'quantity' => 2,
//                 ],
//             ],
//         ];

//         $response = $this->actingAs($this->receptionist)->postJson('/api/v1/invoices', $payload);

//         $response->assertStatus(201)
//             ->assertJsonPath('data.total_amount', 400)
//             ->assertJsonPath('data.status', 'unpaid');

//         $this->assertDatabaseHas('invoices', [
//             'tenant_id' => $this->tenant->id,
//             'patient_id' => $this->patient->id,
//             'total_amount' => 400.00,
//             'status' => 'unpaid',
//         ]);
//     }

//     public function test_doctor_cannot_create_invoice(): void
//     {
//         $payload = [
//             'patient_id' => $this->patient->id,
//             'items' => [
//                 [
//                     'service_id' => $this->service->id,
//                     'price' => 200.00,
//                     'quantity' => 1,
//                 ],
//             ],
//         ];

//         $response = $this->actingAs($this->doctor)->postJson('/api/v1/invoices', $payload);

//         $response->assertStatus(403);
//     }

//     public function test_only_owner_can_soft_delete_invoice(): void
//     {
//         $invoice = Invoice::factory()->create([
//             'tenant_id' => $this->tenant->id,
//             'patient_id' => $this->patient->id,
//         ]);

//         $recResponse = $this->actingAs($this->receptionist)->deleteJson("/api/v1/invoices/{$invoice->id}");
//         $recResponse->assertStatus(403);

//         $ownerResponse = $this->actingAs($this->owner)->deleteJson("/api/v1/invoices/{$invoice->id}");
//         $ownerResponse->assertStatus(200);

//         $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
//     }

//     public function test_can_get_patient_invoice_summary(): void
//     {
//         Invoice::factory()->create([
//             'tenant_id' => $this->tenant->id,
//             'patient_id' => $this->patient->id,
//             'total_amount' => 500.00,
//         ]);

//         $response = $this->actingAs($this->doctor)->getJson("/api/v1/patients/{$this->patient->id}/invoices-summary");

//         $response->assertStatus(200)
//             ->assertJsonPath('data.total_billed', 500)
//             ->assertJsonPath('data.total_invoices_count', 1);
//     }
}
