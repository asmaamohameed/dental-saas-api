<?php

namespace Tests\Feature\Billing;

use App\Billing\Actions\DeletePlannedItem;
use App\Billing\Domain\Exceptions\InvoiceClosedException;
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
use App\Models\AuditLog;
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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceWritersTest extends TestCase
{
    public function test_void_done_line_lowers_total_and_sets_refund_due_without_creating_a_refund(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        [$invoice, $item, $tooth] = $this->doneLine(pay: true);

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
            'reason' => 'Clinical failure',
        ])->assertOk();

        $freshItem = $item->fresh();
        $freshInvoice = $invoice->fresh();
        $this->assertNotNull($freshItem->voided_at);
        $this->assertSame('Clinical failure', $freshItem->void_reason);
        $this->assertSame($doctor->id, $freshItem->voided_by);
        $this->assertNull($freshItem->deleted_at);
        $this->assertSame(TreatmentToothStatus::DONE, $tooth->fresh()->status);
        $this->assertSame('0.00', (string) $freshInvoice->total_amount);
        $this->assertSame('80.00', $freshInvoice->balance()['refund_due']);
        $this->assertTrue($freshInvoice->balance()['has_refund_due']);
        $this->assertSame(0, Refund::query()->count());
        $this->assertAudit($item->id, AuditAction::INVOICE_ITEM_VOIDED, $doctor->id);
    }

    public function test_void_non_done_line_returns_422(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['status' => InvoiceStatus::UNPAID]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Planned manual line',
            'price' => 50,
            'quantity' => 1,
        ]);

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
            'reason' => 'Should not apply',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Only a completed line can be voided.');

        $this->assertNull($item->fresh()->voided_at);
    }

    public function test_reprice_done_line_writes_audit_and_updates_total(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        [$invoice, $item, $tooth] = $this->doneLine();

        $this->postJson("/api/v1/invoice-items/{$item->id}/reprice-done", [
            'price' => 30,
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason']);

        $this->postJson("/api/v1/invoice-items/{$item->id}/reprice-done", [
            'price' => 30,
            'reason' => 'Agreed discount',
        ])->assertOk();

        $this->assertSame('30.00', (string) $item->fresh()->price);
        $this->assertSame('30.00', (string) $invoice->fresh()->total_amount);
        $this->assertSame(TreatmentToothStatus::DONE, $tooth->fresh()->status);
        $this->assertNull($item->fresh()->voided_at);

        $log = $this->assertAudit($item->id, AuditAction::INVOICE_ITEM_DONE_REPRICED, $doctor->id);
        $this->assertSame('80.00', $log->old_values['price']);
        $this->assertSame('30.00', $log->new_values['price']);
        $this->assertSame('Agreed discount', $log->new_values['reason']);
    }

    public function test_delete_planned_item_via_action_lowers_total_and_audits(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PARTIAL]);
        $planned = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Planned line',
            'price' => 40,
            'quantity' => 1,
        ]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Keep',
            'price' => 60,
            'quantity' => 1,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80]);

        DB::transaction(function () use ($invoice, $planned, $user) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            app(DeletePlannedItem::class)->handle($locked, $planned, $user);
        });

        $fresh = $invoice->fresh();
        $this->assertSame('60.00', (string) $fresh->total_amount);
        $this->assertSoftDeleted('invoice_items', ['id' => $planned->id]);
        $this->assertSame(0, Refund::query()->count());
        $this->assertAudit($planned->id, AuditAction::INVOICE_ITEM_PLANNED_DELETED, $user->id);
    }

    public function test_closed_invoices_block_void_reprice_and_delete_planned_action(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);

        foreach ([InvoiceStatus::CANCELLED, InvoiceStatus::REFUNDED] as $status) {
            [$invoice, $item] = $this->doneLine();
            $invoice->update(['status' => $status]);

            $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
                'reason' => 'Nope',
            ])->assertForbidden();

            $this->postJson("/api/v1/invoice-items/{$item->id}/reprice-done", [
                'price' => 10,
                'reason' => 'Nope',
            ])->assertForbidden();

            $planned = InvoiceItem::factory()->create([
                'invoice_id' => $invoice->id,
                'description' => 'Extra planned',
                'price' => 5,
                'quantity' => 1,
            ]);

            try {
                DB::transaction(function () use ($invoice, $planned, $user) {
                    $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                    app(DeletePlannedItem::class)->handle($locked, $planned, $user);
                });
                $this->fail('Expected InvoiceClosedException for '.$status->value);
            } catch (InvoiceClosedException $exception) {
                $this->assertSame('Cancelled or refunded invoices cannot be changed.', $exception->getMessage());
            }
        }
    }

    public function test_void_and_reprice_permission_mapping(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        [$invoice, $item] = $this->doneLine();

        $assistant = User::factory()->assistant()->create(['tenant_id' => $doctor->tenant_id]);
        Sanctum::actingAs($assistant, ['*']);
        $this->postJson("/api/v1/invoice-items/{$item->id}/void", ['reason' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/invoice-items/{$item->id}/reprice-done", [
            'price' => 1,
            'reason' => 'x',
        ])->assertForbidden();

        $receptionist = User::factory()->receptionist()->create(['tenant_id' => $doctor->tenant_id]);
        Sanctum::actingAs($receptionist, ['*']);
        $this->postJson("/api/v1/invoice-items/{$item->id}/void", ['reason' => 'Allowed'])->assertOk();

        [, $item2] = $this->doneLine();
        $owner = User::factory()->owner()->create(['tenant_id' => $doctor->tenant_id]);
        Sanctum::actingAs($owner, ['*']);
        $this->postJson("/api/v1/invoice-items/{$item2->id}/reprice-done", [
            'price' => 70,
            'reason' => 'Owner discount',
        ])->assertOk();
    }

    public function test_void_is_not_allowed_across_tenants(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        [, $item] = $this->doneLine();

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $outsider = User::factory()->doctor()->create(['tenant_id' => $otherTenant->id]);
        Sanctum::actingAs($outsider, ['*']);

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
            'reason' => 'Cross tenant',
        ])->assertNotFound();

        app(CurrentTenant::class)->set($doctor->tenant_id);
        $this->assertNull($item->fresh()->voided_at);
    }

    private function assertAudit(string $itemId, AuditAction $action, string $userId): AuditLog
    {
        $log = AuditLog::query()->where('auditable_id', $itemId)->where('action', $action)->first();
        $this->assertNotNull($log);
        $this->assertSame($userId, $log->user_id);
        $this->assertSame(InvoiceItem::class, $log->auditable_type);

        return $log;
    }

    /**
     * @return array{0: Invoice, 1: InvoiceItem, 2: PatientTreatmentTooth}
     */
    private function doneLine(bool $pay = false): array
    {
        $patient = Patient::factory()->create();
        $treatment = $this->treatment($patient, PatientTreatmentStatus::IN_PROGRESS, TreatmentScope::TOOTH, '80.00');
        $tooth = PatientTreatmentTooth::query()->create([
            'patient_treatment_id' => $treatment->id,
            'tooth_number' => (string) random_int(11, 48),
            'status' => TreatmentToothStatus::DONE,
            'price' => 80,
        ]);
        $invoice = Invoice::factory()->create([
            'patient_id' => $patient->id,
            'total_amount' => 80,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $treatment->id,
            'patient_treatment_tooth_id' => $tooth->id,
            'description' => 'Completed tooth line',
            'price' => 80,
            'quantity' => 1,
        ]);

        if ($pay) {
            Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80]);
            $invoice->update(['status' => InvoiceStatus::PAID]);
        }

        return [$invoice, $item, $tooth];
    }

    private function treatment(Patient $patient, PatientTreatmentStatus $status, TreatmentScope $scope, string $price): PatientTreatment
    {
        $template = TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Treatment '.uniqid(),
            'category' => 'medical',
            'scope' => $scope,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);

        return PatientTreatment::query()->create([
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'scope' => $scope,
            'agreed_price' => $price,
            'clinical_status' => $status,
            'financial_status' => FinancialStatus::UNPAID,
            'consent_status' => ConsentStatus::NOT_REQUIRED,
            'treatment_type' => TreatmentType::ORIGINAL,
            'priority' => TreatmentPriority::MEDIUM,
        ]);
    }
}
