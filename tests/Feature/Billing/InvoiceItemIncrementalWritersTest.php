<?php

namespace Tests\Feature\Billing;

use App\Enums\AuditAction;
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

class InvoiceItemIncrementalWritersTest extends TestCase
{
    public function test_item_update_rejects_done_lines(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        [$invoice, $item] = $this->doneLine();

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'quantity' => 2,
        ])->assertStatus(422)->assertJsonValidationErrors(['item']);
    }

    public function test_destroy_uses_delete_planned_item_and_writes_audit(): void
    {
        $owner = $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 150]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Keep',
            'price' => 100,
            'quantity' => 1,
        ]);
        $planned = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Remove me',
            'price' => 50,
            'quantity' => 1,
        ]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$planned->id}")->assertOk();

        $this->assertSoftDeleted('invoice_items', ['id' => $planned->id]);
        $this->assertDatabaseHas('audit_logs', [
            'auditable_id' => $planned->id,
            'auditable_type' => InvoiceItem::class,
            'action' => AuditAction::INVOICE_ITEM_PLANNED_DELETED->value,
            'user_id' => $owner->id,
        ]);
    }

    public function test_planned_item_edit_returns_422_when_total_would_fall_below_settled(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PARTIAL, 'total_amount' => 1000]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Large line',
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

    public function test_planned_item_delete_returns_422_when_total_would_fall_below_settled(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PARTIAL, 'total_amount' => 150]);
        $keep = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Keep',
            'price' => 100,
            'quantity' => 1,
        ]);
        $remove = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Remove',
            'price' => 50,
            'quantity' => 1,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 120]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$remove->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['item'])
            ->assertJsonPath('errors.item.0', __('billing.total_below_settled'));

        $this->assertNotNull($remove->fresh());
        $this->assertNotNull($keep->fresh());
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
}
