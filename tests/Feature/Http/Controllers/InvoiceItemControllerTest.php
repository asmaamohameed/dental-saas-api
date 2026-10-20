<?php

namespace Tests\Feature\Http\Controllers;

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
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceItemControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role): User
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function manualItemPayload(array $overrides = []): array
    {
        return array_merge([
            'price' => 80,
            'description' => 'Manual billing line item',
            'quantity' => 1,
        ], $overrides);
    }

    public function test_index_returns_items_for_own_tenant_invoice(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $invoice = Invoice::factory()->create();
        InvoiceItem::factory()->count(2)->create(['invoice_id' => $invoice->id]);

        $response = $this->getJson("/api/v1/invoices/{$invoice->id}/items")->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_returns_404_for_invoice_in_another_tenant(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $otherTenant = Tenant::factory()->create();
        $myTenantId = app(CurrentTenant::class)->id();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignInvoice = Invoice::factory()->create();
        app(CurrentTenant::class)->set($myTenantId);

        $this->getJson("/api/v1/invoices/{$foreignInvoice->id}/items")->assertStatus(404);
    }

    public function test_doctor_is_forbidden_from_adding_items(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())
            ->assertCreated();
    }

    public function test_receptionist_can_add_an_item_and_total_is_recalculated(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 0]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload([
            'price' => 80,
            'quantity' => 2,
        ]))->assertCreated();

        $this->assertEquals(160, $invoice->fresh()->total_amount);
    }

    public function test_cannot_add_an_item_to_a_fully_paid_invoice(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PAID]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/items", $this->manualItemPayload())
            ->assertStatus(403);
    }

    public function test_update_rejects_a_new_total_lower_than_payments_already_received(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::PARTIAL, 'total_amount' => 1000]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Existing line',
            'price' => 100,
            'quantity' => 10,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 300]);

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'quantity' => 1,
        ])->assertStatus(422)->assertJsonValidationErrors(['quantity'])
            ->assertJsonPath('errors.quantity.0', __('billing.total_below_settled'));
    }

    public function test_update_returns_404_when_item_does_not_belong_to_the_invoice(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoiceA = Invoice::factory()->create();
        $invoiceB = Invoice::factory()->create();
        $item = InvoiceItem::factory()->create(['invoice_id' => $invoiceB->id]);

        $this->putJson("/api/v1/invoices/{$invoiceA->id}/items/{$item->id}", [
            'quantity' => 3,
        ])->assertStatus(404);
    }

    public function test_destroy_rejects_deleting_the_last_remaining_item(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        $item = InvoiceItem::factory()->create(['invoice_id' => $invoice->id]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['item']);
    }

    public function test_destroy_removes_an_item_and_recalculates_total_when_another_item_remains(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'First line',
            'price' => 100,
            'quantity' => 1,
        ]);
        $itemToDelete = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Second line',
            'price' => 50,
            'quantity' => 1,
        ]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$itemToDelete->id}")->assertOk();

        $this->assertSoftDeleted('invoice_items', ['id' => $itemToDelete->id]);
        $this->assertEquals(100, $invoice->fresh()->total_amount);
    }

    public function test_destroy_returns_404_when_item_does_not_belong_to_the_invoice(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $invoiceA = Invoice::factory()->create();
        $invoiceB = Invoice::factory()->create();
        $item = InvoiceItem::factory()->create(['invoice_id' => $invoiceB->id]);

        $this->deleteJson("/api/v1/invoices/{$invoiceA->id}/items/{$item->id}")->assertStatus(404);
    }

    public function test_void_endpoint_voids_a_done_line_on_a_paid_invoice_and_sets_refund_due(): void
    {
        $doctor = $this->actingAsRole(UserRole::DOCTOR);
        [$invoice, $item] = $this->doneInvoiceItem($doctor->tenant_id, pay: true);

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
            'reason' => 'Failed restoration',
        ])->assertOk();

        $fresh = $invoice->fresh();
        $this->assertNotNull($item->fresh()->voided_at);
        $this->assertSame('0.00', (string) $fresh->total_amount);
        $this->assertSame('80.00', $fresh->balance()['refund_due']);
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_assistant_cannot_call_void_or_reprice_done_endpoints(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $assistant = User::factory()->assistant()->create(['tenant_id' => $tenant->id]);
        Sanctum::actingAs($assistant, ['*']);

        [, $item] = $this->doneInvoiceItem($tenant->id);

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", ['reason' => 'Nope'])->assertForbidden();
        $this->postJson("/api/v1/invoice-items/{$item->id}/reprice-done", [
            'price' => 50,
            'reason' => 'Nope',
        ])->assertForbidden();
    }

    public function test_reprice_done_endpoint_updates_price_and_invoice_total(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        [$invoice, $item] = $this->doneInvoiceItem(app(CurrentTenant::class)->id());

        $this->postJson("/api/v1/invoice-items/{$item->id}/reprice-done", [
            'price' => 45,
            'reason' => 'Discount agreed with patient',
        ])->assertOk();

        $this->assertSame('45.00', (string) $item->fresh()->price);
        $this->assertSame('45.00', (string) $invoice->fresh()->total_amount);
    }

    /**
     * @return array{0: Invoice, 1: InvoiceItem}
     */
    private function doneInvoiceItem(string $tenantId, bool $pay = false): array
    {
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

        if ($pay) {
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80]);
            $invoice->update(['status' => InvoiceStatus::PAID]);
        }

        return [$invoice, $item];
    }
}
