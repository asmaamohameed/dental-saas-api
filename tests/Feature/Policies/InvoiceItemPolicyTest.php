<?php

namespace Tests\Feature\Policies;

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
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceItemPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    private function userWithRole(UserRole $role): User
    {
        return User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => $role]);
    }

    private function invoiceWithStatus(InvoiceStatus $status): Invoice
    {
        return Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => Patient::factory()->create(['tenant_id' => $this->tenant->id]),
            'status' => $status,
        ]);
    }

    private function manualItemPayload(): array
    {
        return [
            'price' => 100,
            'description' => 'Additional charge line item',
            'quantity' => 1,
        ];
    }

    public function test_receptionist_can_view_invoice_items(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $this->getJson("/api/v1/invoices/{$invoice->id}/items")->assertOk();
    }

    public function test_user_from_another_tenant_cannot_view_invoice_items(): void
    {
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $outsider = User::factory()->create(['tenant_id' => $otherTenant->id, 'role' => UserRole::RECEPTIONIST]);
        Sanctum::actingAs($outsider, ['*']);

        $this->getJson("/api/v1/invoices/{$invoice->id}/items")->assertNotFound();
    }

    public function test_receptionist_cannot_add_manual_item_without_change_price_permission(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())->assertForbidden();
    }

    public function test_receptionist_can_add_a_treatment_line_to_a_partial_invoice(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);
        $treatment = $this->plannedTreatmentForInvoice($invoice);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", [
            'patient_treatment_id' => $treatment->id,
            'quantity' => 1,
        ])->assertCreated();
    }

    public function test_owner_can_add_item_to_partial_invoice(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())->assertCreated();
    }

    public function test_owner_can_add_item_to_a_paid_invoice(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PAID);
        InvoiceItem::factory()->create(['invoice_id' => $invoice->id, 'price' => 50, 'quantity' => 1]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 50]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())->assertCreated();
    }

    public function test_doctor_cannot_add_invoice_items(): void
    {
        $user = $this->userWithRole(UserRole::DOCTOR);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())->assertCreated();
    }

    public function test_owner_can_delete_an_item_from_a_partial_invoice(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        Sanctum::actingAs($user, ['*']);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);
        $invoice->items()->create(['description' => 'First line item', 'price' => 50, 'quantity' => 1]);
        $item = $invoice->items()->create(['description' => 'Second line item', 'price' => 50, 'quantity' => 1]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")->assertOk();
    }

    public function test_receptionist_can_delete_an_item_from_a_partial_invoice(): void
    {
        $user = $this->userWithRole(UserRole::RECEPTIONIST);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::PARTIAL);
        $invoice->items()->create(['description' => 'First line item', 'price' => 50, 'quantity' => 1]);
        $item = $invoice->items()->create(['description' => 'Second line item', 'price' => 50, 'quantity' => 1]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")->assertOk();
    }

    public function test_doctor_cannot_delete_an_invoice_item(): void
    {
        $user = $this->userWithRole(UserRole::DOCTOR);
        $invoice = $this->invoiceWithStatus(InvoiceStatus::UNPAID);
        $invoice->items()->create(['description' => 'First line item', 'price' => 50, 'quantity' => 1]);
        $item = $invoice->items()->create(['description' => 'Second line item', 'price' => 50, 'quantity' => 1]);

        Sanctum::actingAs($user, ['*']);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")->assertOk();
    }

    public function test_doctor_can_void_a_completed_line_on_a_paid_invoice(): void
    {
        $user = $this->userWithRole(UserRole::DOCTOR);
        Sanctum::actingAs($user, ['*']);
        [, $item] = $this->doneInvoiceItem(pay: true);

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
            'reason' => 'Clinical adjustment',
        ])->assertOk();
    }

    public function test_assistant_cannot_void_or_reprice_done(): void
    {
        $assistant = $this->userWithRole(UserRole::ASSISTANT);
        Sanctum::actingAs($assistant, ['*']);
        [, $item] = $this->doneInvoiceItem();

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", ['reason' => 'Nope'])->assertForbidden();
        $this->postJson("/api/v1/invoice-items/{$item->id}/reprice-done", [
            'price' => 10,
            'reason' => 'Nope',
        ])->assertForbidden();
    }

    public function test_void_is_forbidden_when_invoice_is_cancelled(): void
    {
        $user = $this->userWithRole(UserRole::OWNER);
        Sanctum::actingAs($user, ['*']);
        [$invoice, $item] = $this->doneInvoiceItem();
        $invoice->update(['status' => InvoiceStatus::CANCELLED]);

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", ['reason' => 'Too late'])->assertForbidden();
    }

    private function plannedTreatmentForInvoice(Invoice $invoice): PatientTreatment
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
            'patient_id' => $invoice->patient_id,
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
            'tooth_number' => '21',
            'status' => TreatmentToothStatus::PLANNED,
            'price' => 80,
        ]);

        return $treatment;
    }

    /**
     * @return array{0: Invoice, 1: InvoiceItem}
     */
    private function doneInvoiceItem(bool $pay = false): array
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
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
            'tooth_number' => '21',
            'status' => TreatmentToothStatus::DONE,
            'price' => 80,
        ]);
        $invoice = Invoice::factory()->create([
            'tenant_id' => $this->tenant->id,
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

        if ($pay) {
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80]);
            $invoice->update(['status' => InvoiceStatus::PAID]);
        }

        return [$invoice, $item];
    }
}
