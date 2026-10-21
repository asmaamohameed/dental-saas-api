<?php

namespace Tests\Feature\Billing;

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
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class PartialInvoiceItemEditTest extends TestCase
{
    public function test_receptionist_can_add_edit_and_delete_planned_lines_on_partial(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PARTIAL, 'total_amount' => 100]);
        $keep = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Keep this billing line',
            'price' => 60,
            'quantity' => 1,
        ]);
        $edit = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Edit this billing line',
            'price' => 40,
            'quantity' => 1,
        ]);
        $treatment = $this->plannedTreatment($invoice->patient);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'patient_treatment_id' => $treatment->id,
            'quantity' => 1,
        ])->assertCreated();

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$edit->id}", [
            'description' => 'Updated planned billing note',
            'quantity' => 1,
        ])->assertOk();

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$keep->id}")->assertOk();
        $this->assertSoftDeleted('invoice_items', ['id' => $keep->id]);
    }

    public function test_receptionist_edit_on_partial_returns_422_when_total_would_fall_below_settled(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PARTIAL, 'total_amount' => 1000]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Large planned line',
            'price' => 100,
            'quantity' => 10,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300]);

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'quantity' => 1,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['quantity'])
            ->assertJsonPath('errors.quantity.0', __('billing.total_below_settled'));
    }

    public function test_receptionist_cannot_edit_a_done_line_on_partial(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        [$invoice, $item] = $this->doneLine();
        $invoice->update(['status' => InvoiceStatus::PARTIAL]);

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'quantity' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['item']);
    }

    public function test_receptionist_cannot_mutate_items_on_refunded_or_cancelled(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);

        foreach ([InvoiceStatus::REFUNDED, InvoiceStatus::CANCELLED] as $status) {
            $invoice = Invoice::factory()->create(['status' => $status]);
            $item = InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'description' => 'Closed invoice line',
                'price' => 40,
                'quantity' => 1,
            ]);
            InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'description' => 'Second closed invoice line',
                'price' => 40,
                'quantity' => 1,
            ]);

            $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
                'price' => 10,
                'description' => 'Should not be added',
                'quantity' => 1,
            ])->assertForbidden();

            $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
                'quantity' => 2,
            ])->assertForbidden();

            $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")->assertForbidden();
        }
    }

    /**
     * @return array{0: Invoice, 1: InvoiceItem}
     */
    private function doneLine(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $patient = Patient::factory()->create(['tenant_id' => $tenantId]);
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
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
            'agreed_price' => 80,
            'clinical_status' => PatientTreatmentStatus::IN_PROGRESS,
            'financial_status' => FinancialStatus::UNPAID,
            'consent_status' => ConsentStatus::NOT_REQUIRED,
            'treatment_type' => TreatmentType::ORIGINAL,
            'priority' => TreatmentPriority::MEDIUM,
        ]);
        $tooth = PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => '11',
            'status' => TreatmentToothStatus::DONE,
            'price' => 80,
        ]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $tenantId,
            'patient_id' => $patient->id,
            'total_amount' => 80,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $treatment->id,
            'patient_treatment_tooth_id' => $tooth->id,
            'price' => 80,
            'quantity' => 1,
        ]);

        return [$invoice, $item];
    }

    private function plannedTreatment(Patient $patient): PatientTreatment
    {
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 80,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);
        $treatment = PatientTreatment::query()->create([
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'scope' => TreatmentScope::TOOTH,
            'agreed_price' => 80,
            'clinical_status' => PatientTreatmentStatus::PLANNED,
            'financial_status' => FinancialStatus::UNPAID,
            'consent_status' => ConsentStatus::NOT_REQUIRED,
            'treatment_type' => TreatmentType::ORIGINAL,
            'priority' => TreatmentPriority::MEDIUM,
        ]);
        PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => '16',
            'status' => TreatmentToothStatus::PLANNED,
            'price' => 80,
        ]);

        return $treatment;
    }
}
