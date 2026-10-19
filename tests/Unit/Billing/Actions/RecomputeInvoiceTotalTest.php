<?php

namespace Tests\Unit\Billing\Actions;

use App\Billing\Actions\RecomputeInvoiceTotal;
use App\Billing\Queries\InvoiceItemStateSql;
use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
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
use App\Models\TreatmentTemplate;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecomputeInvoiceTotalTest extends TestCase
{
    public function test_stored_total_matches_billable_lines_only(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $invoice = Invoice::factory()->create([
            'patient_id' => $patient->id,
            'total_amount' => 999,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Billable manual',
            'price' => 100,
            'quantity' => 1,
        ]);

        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Tooth treatment',
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);

        $treatment = PatientTreatment::query()->create([
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'scope' => TreatmentScope::TOOTH,
            'agreed_price' => 100,
            'clinical_status' => PatientTreatmentStatus::IN_PROGRESS,
            'financial_status' => FinancialStatus::UNPAID,
            'consent_status' => ConsentStatus::NOT_REQUIRED,
            'treatment_type' => TreatmentType::ORIGINAL,
            'priority' => TreatmentPriority::MEDIUM,
        ]);

        $doneTooth = PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => '11',
            'status' => TreatmentToothStatus::DONE,
            'price' => 40,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $treatment->id,
            'patient_treatment_tooth_id' => $doneTooth->id,
            'price' => 40,
            'quantity' => 1,
        ]);

        $skippedTooth = PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => '12',
            'status' => TreatmentToothStatus::SKIPPED,
            'price' => 75,
        ]);

        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $treatment->id,
            'patient_treatment_tooth_id' => $skippedTooth->id,
            'price' => 75,
            'quantity' => 1,
        ]);

        $voidedItem = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Voided manual',
            'price' => 50,
            'quantity' => 1,
        ]);

        DB::table('invoice_items')->where('id', $voidedItem->id)->update([
            'voided_at' => now(),
            'void_reason' => 'test void',
            'voided_by' => $doctor->id,
        ]);

        $expected = app(InvoiceItemStateSql::class)->totalsForInvoice((string) $invoice->id)['total'];

        $result = app(RecomputeInvoiceTotal::class)->handle($invoice);

        $this->assertSame(bcadd($expected, '0', 2), (string) $result->total_amount);
        $this->assertSame('140.00', (string) $result->total_amount);
    }

    public function test_recompute_is_idempotent(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 0]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Line',
            'price' => 33.50,
            'quantity' => 2,
        ]);

        $action = app(RecomputeInvoiceTotal::class);
        $first = $action->handle($invoice);
        $second = $action->handle($first);

        $this->assertSame('67.00', (string) $first->total_amount);
        $this->assertSame((string) $first->total_amount, (string) $second->total_amount);
    }
}
