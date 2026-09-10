<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceImmutableFieldsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
        $this->owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($this->owner, ['*']);
    }

    // --- appointment_id: locked always, regardless of payment status ---

    public function test_appointment_id_cannot_be_changed_on_an_unpaid_invoice(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $otherAppointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'appointment_id' => $otherAppointment->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_updating_other_fields_without_touching_appointment_id_still_works(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherPatient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'status' => InvoiceStatus::UNPAID,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $otherPatient->id,
        ])->assertOk();
    }

    // --- patient_id: only locked once a payment exists (PARTIAL) ---

    public function test_patient_id_can_be_changed_on_an_unpaid_invoice(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherPatient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'status' => InvoiceStatus::UNPAID,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $otherPatient->id,
        ])->assertOk()->assertJsonPath('data.patient_id', $otherPatient->id);
    }

    public function test_patient_id_cannot_be_changed_once_invoice_has_a_partial_payment(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherPatient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'status' => InvoiceStatus::PARTIAL,
            'total_amount' => 200,
        ]);
        Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'received_by' => $this->owner->id,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $otherPatient->id,
        ])->assertStatus(422)->assertJsonValidationErrors(['patient_id']);
    }

    public function test_sending_the_same_patient_id_on_a_partial_invoice_is_allowed(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'status' => InvoiceStatus::PARTIAL,
            'total_amount' => 200,
        ]);
        Payment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'received_by' => $this->owner->id,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $patient->id,
        ])->assertOk();
    }
}
