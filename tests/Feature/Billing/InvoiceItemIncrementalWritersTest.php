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
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Services\InvoiceService;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvoiceItemIncrementalWritersTest extends TestCase
{
    public function test_put_invoice_appends_a_line_without_id(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 50]);
        $existing = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Keep me',
            'price' => 50,
            'quantity' => 1,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [
                [
                    'id' => $existing->id,
                    'price' => 50,
                    'description' => 'Keep me',
                    'quantity' => 1,
                ],
                [
                    'price' => 25,
                    'description' => 'New manual line item',
                    'quantity' => 2,
                ],
            ],
        ])->assertOk();

        $invoice->refresh();
        $this->assertCount(2, $invoice->items);
        $this->assertEquals(100, (float) $invoice->total_amount);
    }

    public function test_put_invoice_updates_an_existing_line_by_id(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 100]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Editable line',
            'price' => 100,
            'quantity' => 1,
        ]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Other line',
            'price' => 10,
            'quantity' => 1,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [
                [
                    'id' => $item->id,
                    'price' => 40,
                    'description' => 'Editable line',
                    'quantity' => 2,
                ],
            ],
        ])->assertOk();

        $this->assertSame('40.00', (string) $item->fresh()->price);
        $this->assertSame(2, (int) $item->fresh()->quantity);
        $this->assertCount(2, $invoice->fresh()->items);
        $this->assertEquals(90, (float) $invoice->fresh()->total_amount);
    }

    public function test_put_invoice_does_not_delete_lines_missing_from_payload(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        $keep = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Stay on invoice',
            'price' => 30,
            'quantity' => 1,
        ]);
        $alsoKeep = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Also stays',
            'price' => 20,
            'quantity' => 1,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [
                [
                    'id' => $keep->id,
                    'price' => 35,
                    'description' => 'Stay on invoice',
                    'quantity' => 1,
                ],
            ],
        ])->assertOk();

        $this->assertNotNull($alsoKeep->fresh());
        $this->assertCount(2, $invoice->fresh()->items);
    }

    public function test_put_invoice_rejects_done_or_voided_line_ids(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        [$invoice, $doneItem] = $this->doneLine();

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [
                [
                    'id' => $doneItem->id,
                    'price' => 10,
                    'description' => 'Should not apply',
                    'quantity' => 1,
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.id']);

        DB::table('invoice_items')->where('id', $doneItem->id)->update([
            'voided_at' => now(),
            'void_reason' => 'Test void',
            'voided_by' => User::factory()->create()->id,
        ]);

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [
                [
                    'id' => $doneItem->id,
                    'price' => 10,
                    'description' => 'Should not apply',
                    'quantity' => 1,
                ],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors(['items.0.id']);
    }

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

    public function test_invoice_service_incremental_update_via_items_array(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $service = app(InvoiceService::class);

        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID, 'total_amount' => 40]);
        $first = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'First',
            'price' => 40,
            'quantity' => 1,
        ]);
        $second = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Second',
            'price' => 10,
            'quantity' => 1,
        ]);

        $updated = $service->update($invoice, [
            'items' => [
                [
                    'id' => $first->id,
                    'price' => 45,
                    'description' => 'First',
                    'quantity' => 1,
                ],
                [
                    'price' => 5,
                    'description' => 'Third manual line',
                    'quantity' => 1,
                ],
            ],
        ]);

        $this->assertCount(3, $updated->items);
        $this->assertNotNull($second->fresh());
        $this->assertEquals(60, (float) $updated->total_amount);
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
