<?php

namespace Tests\Feature\Invoices;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceUpdateTest extends TestCase
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
        $user = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_owner_can_update_patient_id_on_an_unpaid_invoice(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $newPatient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $newPatient->id,
        ])->assertOk()->assertJsonPath('data.patient_id', $newPatient->id);

        $invoice->refresh();
        $this->assertSame($newPatient->id, $invoice->patient_id);
    }

    public function test_updating_any_field_on_a_paid_invoice_is_blocked(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $invoice = Invoice::factory()->paid()->create(['tenant_id' => $this->tenant->id]);
        $originalPatientId = $invoice->patient_id;
        $newPatient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $newPatient->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['invoice']);

        $invoice->refresh();
        $this->assertSame($originalPatientId, $invoice->patient_id);
    }

    public function test_updating_base_fields_on_a_partial_invoice_without_items_succeeds(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => InvoiceStatus::PARTIAL,
        ]);
        $newPatient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $newPatient->id,
        ])->assertOk();

        $invoice->refresh();
        $this->assertSame($newPatient->id, $invoice->patient_id);
    }

    public function test_updating_items_on_a_partial_invoice_is_blocked_even_for_owner(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => InvoiceStatus::PARTIAL,
        ]);
        $service = Service::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [
                ['service_id' => $service->id, 'price' => 50, 'quantity' => 1],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['items']);
    }

    public function test_appointment_must_belong_to_the_given_patient(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $patientA = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $patientB = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);

        $appointmentForA = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patientA->id,
            'doctor_id' => $doctor->id,
            'created_by' => $doctor->id,
        ]);

        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patientA->id,
            'status' => InvoiceStatus::UNPAID,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $patientB->id,
            'appointment_id' => $appointmentForA->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_doctor_cannot_reach_invoice_update_endpoint_at_all(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $invoice = Invoice::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $invoice->patient_id,
        ])->assertForbidden();
    }
}
