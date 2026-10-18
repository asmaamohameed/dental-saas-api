<?php

namespace Tests\Feature\Billing;

use App\Billing\Actions\RecomputeInvoiceTotal;
use App\Billing\Domain\InvoiceItemStateResolver;
use App\Billing\Queries\InvoiceItemFactsReader;
use App\Billing\Queries\InvoiceItemStateSql;
use App\Enums\AuditAction;
use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\PaymentType;
use App\Enums\TreatmentPlanStatus;
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
use App\Models\TreatmentPlan;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InvoiceWritersTest extends TestCase
{
    public function test_stored_total_matches_the_billable_sum_after_each_writer(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $patient = Patient::factory()->create();
        $service = app(InvoiceService::class);

        $created = $service->create([
            'patient_id' => $patient->id,
            'items' => [
                ['price' => 40, 'description' => 'Manual billing line', 'quantity' => 2],
            ],
        ], $user->id);
        $this->assertBillableTotal($created);

        $this->postJson("/api/v1/invoices/{$created->id}/items", [
            'price' => 15,
            'description' => 'Second manual line',
            'quantity' => 1,
        ])->assertCreated();
        $this->assertBillableTotal($created->fresh());

        $line = $created->items()->first();
        $this->putJson("/api/v1/invoices/{$created->id}/items/{$line->id}", [
            'quantity' => 1,
        ])->assertOk();
        $this->assertBillableTotal($created->fresh());

        $treatment = $this->treatment($patient, PatientTreatmentStatus::PLANNED, TreatmentScope::FULL_MOUTH, '80.00');
        $initial = $service->createInitialInvoiceForTreatment($treatment, $user->id);
        $this->assertNotNull($initial);
        $this->assertSame('80.00', (string) $initial->fresh()->total_amount);
        $this->assertBillableTotal($initial->fresh());

        $treatment->update(['agreed_price' => '55.00']);
        $service->syncTreatmentInvoiceItems($treatment->fresh());
        $this->assertSame('55.00', (string) $initial->fresh()->total_amount);
        $this->assertBillableTotal($initial->fresh());

        $initial->items()->create([
            'description' => 'Kept manual line',
            'price' => 25,
            'quantity' => 1,
        ]);
        DB::transaction(fn () => $service->removeUnpaidInvoicesForTreatment($treatment->fresh()));
        $afterRemoval = $initial->fresh();
        $this->assertSame('25.00', (string) $afterRemoval->total_amount);
        $this->assertBillableTotal($afterRemoval);
    }

    public function test_deleting_a_planned_line_lowers_the_total_and_sets_refund_due_without_a_refund(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PAID]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Planned manual line',
            'price' => 100,
            'quantity' => 1,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")->assertOk();

        $fresh = $invoice->fresh();
        $this->assertSame('0.00', (string) $fresh->total_amount);
        $this->assertSame('100.00', $fresh->balance()['refund_due']);
        $this->assertTrue($fresh->balance()['has_refund_due']);
        $this->assertSame(InvoiceStatus::PAID, $fresh->status);
        $this->assertSame(0, Payment::query()->where('type', PaymentType::Refund->value)->count());
        $this->assertSoftDeleted('invoice_items', ['id' => $item->id]);
        $this->assertBillableTotal($fresh);
        $this->assertAudit($item->id, AuditAction::INVOICE_ITEM_PLANNED_DELETED, $user->id);
    }

    public function test_done_delete_and_silent_reprice_are_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        [$invoice, $item] = $this->doneLine();

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'A completed line cannot be deleted or changed. Void it or reprice it with a reason.');

        $this->putJson("/api/v1/invoices/{$invoice->id}/items/{$item->id}", [
            'price' => 10,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'A completed line cannot be deleted or changed. Void it or reprice it with a reason.');

        $this->assertSame('80.00', (string) $item->fresh()->price);
        $this->assertNull($item->fresh()->deleted_at);
    }

    public function test_void_keeps_the_row_and_the_tooth_done_and_requires_reason_and_refund_permission(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        [$invoice, $item, $tooth] = $this->doneLine(pay: true);
        $receptionist = User::factory()->receptionist()->create(['tenant_id' => $doctor->tenant_id]);

        Sanctum::actingAs($receptionist);
        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
            'reason' => 'Clinical failure',
        ])->assertForbidden();

        Sanctum::actingAs($doctor);
        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);

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
        $this->assertSame(0, Payment::query()->where('type', PaymentType::Refund->value)->count());
        $this->assertBillableTotal($freshInvoice);
        $this->assertAudit($item->id, AuditAction::INVOICE_ITEM_VOIDED, $doctor->id);
    }

    public function test_reprice_done_requires_refund_permission_and_writes_an_audit_row(): void
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
        $this->assertBillableTotal($invoice->fresh());

        $log = $this->assertAudit($item->id, AuditAction::INVOICE_ITEM_DONE_REPRICED, $doctor->id);
        $this->assertSame('80.00', $log->old_values['price']);
        $this->assertSame('30.00', $log->new_values['price']);
        $this->assertSame('Agreed discount', $log->new_values['reason']);
    }

    public function test_refund_of_a_soft_deleted_planned_item_is_capped_by_refund_due(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::UNPAID]);
        $live = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Live line',
            'price' => 70,
            'quantity' => 1,
        ]);
        $planned = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Planned line',
            'price' => 30,
            'quantity' => 1,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80]);
        DB::transaction(function () use ($invoice) {
            $locked = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            app(RecomputeInvoiceTotal::class)->handle($locked);
        });

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$planned->id}")->assertOk();
        $this->assertSame('10.00', $invoice->fresh()->balance()['refund_due']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $planned->id,
            'amount' => 11,
            'reason' => 'duplicate_payment',
        ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $planned->id,
            'amount' => 10,
            'reason' => 'duplicate_payment',
        ])->assertCreated();

        $this->assertSame('0.00', $invoice->fresh()->balance()['refund_due']);
        $this->assertSame(1, Payment::query()->where('type', PaymentType::Refund->value)->count());
        $this->assertNotNull($live->fresh());
    }

    public function test_refund_of_a_live_item_keeps_the_phase1_cap_when_refund_due_is_lower(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::UNPAID]);
        $live = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Live line',
            'price' => 70,
            'quantity' => 1,
        ]);
        $planned = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Planned line',
            'price' => 30,
            'quantity' => 1,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80]);
        DB::transaction(function () use ($invoice) {
            app(RecomputeInvoiceTotal::class)->handle(Invoice::query()->lockForUpdate()->findOrFail($invoice->id));
        });

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$planned->id}")->assertOk();
        $this->assertSame('10.00', $invoice->fresh()->balance()['refund_due']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $live->id,
            'amount' => 70,
            'reason' => 'incorrect_payment',
        ])->assertCreated();

        $fresh = $invoice->fresh();
        $this->assertSame('10.00', $fresh->balance()['net_paid']);
        $this->assertSame('0.00', $fresh->balance()['refund_due']);
    }

    public function test_put_refuses_a_payload_that_targets_a_done_line(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        [$invoice, $item] = $this->doneLine();

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [[
                'id' => $item->id,
                'price' => 10,
                'description' => 'Should not replace the done line',
                'quantity' => 1,
            ]],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'A completed line cannot be deleted or changed. Void it or reprice it with a reason.');

        $this->assertSame('80.00', (string) $item->fresh()->price);
    }

    public function test_a_cancelled_full_mouth_line_is_excluded_when_the_total_is_recomputed(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $patient = Patient::factory()->create();
        $treatment = $this->treatment($patient, PatientTreatmentStatus::CANCELLED, TreatmentScope::FULL_MOUTH, '80.00');
        $invoice = Invoice::factory()->create([
            'patient_id' => $patient->id,
            'total_amount' => 80,
            'status' => InvoiceStatus::UNPAID,
        ]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'patient_treatment_id' => $treatment->id,
            'description' => 'Cancelled full-mouth line',
            'price' => 80,
            'quantity' => 1,
        ]);

        DB::transaction(function () use ($invoice) {
            app(RecomputeInvoiceTotal::class)->handle(Invoice::query()->lockForUpdate()->findOrFail($invoice->id));
        });

        $fresh = $invoice->fresh();
        $this->assertSame('0.00', (string) $fresh->total_amount);
        $this->assertSame(1, $fresh->items()->count());
        $this->assertBillableTotal($fresh);
        $this->assertNotNull($user->id);
    }

    public function test_refund_due_is_never_negative(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 140, 'status' => InvoiceStatus::PARTIAL]);
        $drop = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Drop this line',
            'price' => 40,
            'quantity' => 1,
        ]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'description' => 'Keep this line',
            'price' => 100,
            'quantity' => 1,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 20]);
        DB::transaction(function () use ($invoice) {
            app(RecomputeInvoiceTotal::class)->handle(Invoice::query()->lockForUpdate()->findOrFail($invoice->id));
        });

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/items/{$drop->id}")->assertOk();

        $due = $invoice->fresh()->balance()['refund_due'];
        $this->assertSame('0.00', $due);
        $this->assertGreaterThanOrEqual(0, bccomp($due, '0', 2));
    }

    public function test_plan_invoice_refuses_a_patient_change(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $patient = Patient::factory()->create();
        $plan = TreatmentPlan::query()->create([
            'patient_id' => $patient->id,
            'title' => 'Active plan',
            'status' => TreatmentPlanStatus::ACTIVE,
        ]);
        $invoice = Invoice::factory()->create([
            'patient_id' => $patient->id,
            'treatment_plan_id' => $plan->id,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $other = Patient::factory()->create();

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'patient_id' => $other->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['patient_id']);

        $this->assertSame($patient->id, $invoice->fresh()->patient_id);
    }

    private function assertBillableTotal(Invoice $invoice): void
    {
        $stored = bcadd((string) $invoice->total_amount, '0', 2);
        $sql = bcadd(app(InvoiceItemStateSql::class)->totalsForInvoice((string) $invoice->id)['total'], '0', 2);

        $this->assertSame($stored, $sql, 'Stored total diverged from the SQL billable predicate.');
        $this->assertSame($stored, $this->independentBillableTotal($invoice), 'Stored total diverged from the PHP billable predicate.');
    }

    private function independentBillableTotal(Invoice $invoice): string
    {
        $resolver = new InvoiceItemStateResolver;
        $reader = new InvoiceItemFactsReader;
        $sum = '0.00';

        $items = InvoiceItem::withTrashed()->where('invoice_id', $invoice->id)->get();
        foreach ($items as $item) {
            if (! $resolver->billable($reader->read($item))) {
                continue;
            }

            $sum = bcadd($sum, bcmul((string) $item->price, (string) $item->quantity, 2), 2);
        }

        return $sum;
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
            'tooth_number' => '16',
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
            'cancellation_reason' => $status === PatientTreatmentStatus::CANCELLED ? 'Patient declined' : null,
        ]);
    }
}
