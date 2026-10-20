<?php

namespace Tests\Feature\Services;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Tenant;
use App\Services\InvoiceService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_recalculate_status_sets_paid_when_zero_total_and_zero_paid(): void
    {
        $invoice = Invoice::factory()->create(['total_amount' => 0, 'status' => InvoiceStatus::PARTIAL]);

        $this->service->recalculateStatus($invoice);

        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()->status);
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
