<?php

namespace Tests\Feature\Services;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InvoiceService::class);

        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
    }

    private function makePatient(): Patient
    {
        return Patient::factory()->create();
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    // ---------- create() ----------

    public function test_create_computes_total_from_service_default_price(): void
    {
        $patient = $this->makePatient();
        $user = $this->makeUser();
        $service = Service::factory()->create(['default_price' => 100, 'is_other' => false]);

        $invoice = $this->service->create([
            'patient_id' => $patient->id,
            'items' => [
                ['service_id' => $service->id, 'quantity' => 2],
            ],
        ], $user->id);

        $this->assertEquals(200, $invoice->total_amount);
        $this->assertSame(InvoiceStatus::UNPAID, $invoice->status);
        $this->assertSame($user->id, $invoice->created_by);
    }

    public function test_create_uses_submitted_price_for_other_service(): void
    {
        $patient = $this->makePatient();
        $user = $this->makeUser();
        $otherService = Service::factory()->create(['is_other' => true, 'default_price' => 0]);

        $invoice = $this->service->create([
            'patient_id' => $patient->id,
            'items' => [
                ['service_id' => $otherService->id, 'price' => 250, 'quantity' => 1, 'description' => 'Custom work'],
            ],
        ], $user->id);

        $this->assertEquals(250, $invoice->total_amount);
    }

    public function test_create_throws_when_service_does_not_belong_to_current_tenant(): void
    {
        $patient = $this->makePatient();
        $user = $this->makeUser();

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignService = Service::factory()->create();
        app(CurrentTenant::class)->set(app(CurrentTenant::class)->id() ?? $otherTenant->id);

        // نرجع للـ tenant الأصلي
        $originalTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($originalTenant->id);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'patient_id' => $patient->id,
            'items' => [
                ['service_id' => $foreignService->id, 'quantity' => 1],
            ],
        ], $user->id);
    }

    // ---------- update() ----------

    public function test_update_rejects_a_fully_paid_invoice(): void
    {
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PAID]);

        $this->expectException(ValidationException::class);

        $this->service->update($invoice, ['patient_id' => $this->makePatient()->id]);
    }

    public function test_update_rejects_changing_appointment_id(): void
    {
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);

        $this->expectException(ValidationException::class);

        $this->service->update($invoice, ['appointment_id' => Appointment::factory()->create()->id]);
    }

    public function test_update_rejects_item_changes_on_a_partial_invoice(): void
    {
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PARTIAL]);
        $service = Service::factory()->create();

        $this->expectException(ValidationException::class);

        $this->service->update($invoice, [
            'items' => [['service_id' => $service->id, 'quantity' => 1, 'price' => 50]],
        ]);
    }

    public function test_update_rejects_changing_patient_on_a_partial_invoice(): void
    {
        $originalPatient = $this->makePatient();
        $invoice = Invoice::factory()->create([
            'status' => InvoiceStatus::PARTIAL,
            'patient_id' => $originalPatient->id,
        ]);
        $newPatient = $this->makePatient();

        $this->expectException(ValidationException::class);

        $this->service->update($invoice, ['patient_id' => $newPatient->id]);
    }

    public function test_update_allows_changing_patient_on_a_partial_invoice_to_the_same_patient(): void
    {
        $patient = $this->makePatient();
        $invoice = Invoice::factory()->create([
            'status' => InvoiceStatus::PARTIAL,
            'patient_id' => $patient->id,
        ]);

        $updated = $this->service->update($invoice, ['patient_id' => $patient->id]);

        $this->assertSame($patient->id, $updated->patient_id);
    }

    public function test_update_rejects_new_total_lower_than_existing_payments(): void
    {
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 500]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300]);
        $cheapService = Service::factory()->create(['default_price' => 50, 'is_other' => false]);

        $this->expectException(ValidationException::class);

        $this->service->update($invoice, [
            'items' => [['service_id' => $cheapService->id, 'quantity' => 1]],
        ]);
    }

    public function test_update_replaces_items_and_recalculates_total(): void
    {
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 100]);
        $service = Service::factory()->create(['default_price' => 75, 'is_other' => false]);

        $updated = $this->service->update($invoice, [
            'items' => [['service_id' => $service->id, 'quantity' => 2]],
        ]);

        $this->assertEquals(150, $updated->total_amount);
        $this->assertCount(1, $updated->items);
    }

    // ---------- delete() ----------

    public function test_delete_throws_when_invoice_has_payments(): void
    {
        $invoice = Invoice::factory()->create();
        Payment::factory()->create(['invoice_id' => $invoice->id]);

        $this->expectException(InvoiceHasPaymentsException::class);

        $this->service->delete($invoice);
    }

    public function test_delete_marks_cancelled_and_soft_deletes_when_no_payments(): void
    {
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);

        $result = $this->service->delete($invoice);

        $this->assertTrue($result);
        $this->assertSoftDeleted('invoices', ['id' => $invoice->id]);
    }

    // ---------- recalculateStatus() ----------

    public function test_recalculate_status_sets_unpaid_when_zero_total_and_zero_paid(): void
    {
        $invoice = Invoice::factory()->create(['total_amount' => 0, 'status' => InvoiceStatus::PARTIAL]);

        $this->service->recalculateStatus($invoice);

        $this->assertSame(InvoiceStatus::UNPAID, $invoice->fresh()->status);
    }

    public function test_recalculate_status_sets_partial_when_partially_paid(): void
    {
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::UNPAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->service->recalculateStatus($invoice);

        $this->assertSame(InvoiceStatus::PARTIAL, $invoice->fresh()->status);
    }

    public function test_recalculate_status_sets_paid_when_fully_paid(): void
    {
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::UNPAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 200]);

        $this->service->recalculateStatus($invoice);

        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()->status);
    }

    // ---------- getPatientSummary() ----------

    public function test_get_patient_summary_aggregates_billed_and_paid_amounts(): void
    {
        $patient = $this->makePatient();
        $invoiceA = Invoice::factory()->create(['patient_id' => $patient->id, 'total_amount' => 300]);
        $invoiceB = Invoice::factory()->create(['patient_id' => $patient->id, 'total_amount' => 200]);
        Payment::factory()->create(['invoice_id' => $invoiceA->id, 'amount' => 300]);
        Payment::factory()->create(['invoice_id' => $invoiceB->id, 'amount' => 50]);

        $summary = $this->service->getPatientSummary($patient->id);

        $this->assertSame(2, $summary['total_invoices_count']);
        $this->assertEquals(500, $summary['total_billed']);
        $this->assertEquals(350, $summary['total_paid']);
        $this->assertEquals(150, $summary['total_remaining']);
    }
}
