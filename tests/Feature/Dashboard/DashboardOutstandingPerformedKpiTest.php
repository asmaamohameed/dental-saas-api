<?php

namespace Tests\Feature\Dashboard;

use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentPriority;
use App\Enums\TreatmentScope;
use App\Enums\TreatmentToothStatus;
use App\Enums\TreatmentType;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentTooth;
use App\Models\Payment;
use App\Models\TreatmentTemplate;
use Tests\TestCase;

class DashboardOutstandingPerformedKpiTest extends TestCase
{
    public function test_financial_analytics_exposes_outstanding_performed_all_time_not_legacy_key(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);

        $response = $this->getJson('/api/v1/dashboard/financial-analytics')->assertOk();

        $kpis = $response->json('data.kpis');
        $this->assertIsArray($kpis);
        $this->assertArrayHasKey('outstanding_performed_all_time', $kpis);
        $this->assertArrayNotHasKey('outstanding_balance_all_time', $kpis);
    }

    public function test_planned_only_invoice_contributes_zero(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 300,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $this->plannedToothLine($invoice, '16', 300);

        $this->assertSame(0.0, $this->outstandingPerformedKpi());
    }

    public function test_done_item_with_no_payment_counts_its_price(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 120,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $this->doneToothLine($invoice, '16', 120);

        $this->assertSame(120.0, $this->outstandingPerformedKpi());
    }

    public function test_done_item_partly_paid_counts_remainder(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 200,
            'status' => InvoiceStatus::PARTIAL,
        ]);
        $this->doneToothLine($invoice, '16', 200);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 75]);

        $this->assertSame(125.0, $this->outstandingPerformedKpi());
    }

    public function test_paid_more_than_performed_counts_zero_not_negative(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 500,
            'status' => InvoiceStatus::PARTIAL,
        ]);
        $this->plannedToothLine($invoice, '16', 400);
        $this->doneToothLine($invoice, '26', 100);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 250]);

        $this->assertSame(0.0, $this->outstandingPerformedKpi());
    }

    public function test_refunded_invoice_is_ignored(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 90,
            'status' => InvoiceStatus::REFUNDED,
        ]);
        $this->doneToothLine($invoice, '16', 90);

        $this->assertSame(0.0, $this->outstandingPerformedKpi());
    }

    public function test_cancelled_invoice_is_ignored(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 90,
            'status' => InvoiceStatus::CANCELLED,
        ]);
        $this->doneToothLine($invoice, '16', 90);

        $this->assertSame(0.0, $this->outstandingPerformedKpi());
    }

    private function outstandingPerformedKpi(): float
    {
        return (float) $this->getJson('/api/v1/dashboard/financial-analytics')
            ->assertOk()
            ->json('data.kpis.outstanding_performed_all_time');
    }

    private function plannedToothLine(Invoice $invoice, string $toothNumber, int $price): InvoiceItem
    {
        return $this->toothLine($invoice, $toothNumber, TreatmentToothStatus::PLANNED, $price);
    }

    private function doneToothLine(Invoice $invoice, string $toothNumber, int $price): InvoiceItem
    {
        return $this->toothLine($invoice, $toothNumber, TreatmentToothStatus::DONE, $price);
    }

    private function toothLine(
        Invoice $invoice,
        string $toothNumber,
        TreatmentToothStatus $status,
        int $price,
    ): InvoiceItem {
        $patient = Patient::factory()->create();
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'حشو',
            'name_en' => 'Filling',
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => $price,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);
        $treatment = PatientTreatment::query()->create([
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'scope' => TreatmentScope::TOOTH,
            'agreed_price' => $price,
            'clinical_status' => PatientTreatmentStatus::IN_PROGRESS,
            'financial_status' => FinancialStatus::UNPAID,
            'consent_status' => ConsentStatus::NOT_REQUIRED,
            'treatment_type' => TreatmentType::ORIGINAL,
            'priority' => TreatmentPriority::MEDIUM,
        ]);

        $tooth = PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => $toothNumber,
            'status' => $status,
            'price' => $price,
        ]);

        return InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $treatment->id,
            'patient_treatment_tooth_id' => $tooth->id,
            'price' => $price,
            'quantity' => 1,
        ]);
    }
}
