<?php

namespace Tests\Feature\Billing;

use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\RefundReasonCode;
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
use App\Models\TreatmentTemplate;
use App\Services\InvoiceService;
use App\Support\Tenancy\CurrentTenant;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class RefundTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function refundPayload(array $overrides = []): array
    {
        return array_merge([
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
            'amount' => 100,
        ], $overrides);
    }

    public function test_successful_refund_creates_record_and_recalculates_status_without_changing_payments(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::PARTIAL]);
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 80,
            'method' => 'card',
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 80,
        ]))->assertCreated();

        $response->assertJsonPath('data.amount', 80)
            ->assertJsonPath('data.reason_code', RefundReasonCode::DUPLICATE_PAYMENT->value)
            ->assertJsonPath('data.reason_note', null)
            ->assertJsonPath('data.method', 'card')
            ->assertJsonPath('data.invoice_item_id', null);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::UNPAID, $invoice->status);
        $this->assertEquals(200, $invoice->remaining_amount);
        $this->assertEquals(200, (float) $invoice->total_amount);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'amount' => 80,
            'method' => 'card',
        ]);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_partial_refund_leaves_remaining_and_partial_status(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::PARTIAL]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 40,
        ]))->assertCreated()->assertJsonPath('data.amount', 40);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PARTIAL, $invoice->status);
        $this->assertEquals(140, $invoice->remaining_amount);
    }

    public function test_refund_amount_over_net_paid_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 500, 'status' => InvoiceStatus::PARTIAL]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 120, 'method' => 'cash']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 500,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_two_refunds_on_one_invoice_are_allowed_up_to_net_paid(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 30]))
            ->assertCreated();
        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 40,
            'reason_code' => RefundReasonCode::INCORRECT_PAYMENT->value,
        ]))->assertCreated();

        $this->assertDatabaseCount('refunds', 2);
        $this->assertEquals(70, (float) Refund::query()->where('invoice_id', $invoice->id)->sum('amount'));
        $this->assertSame(InvoiceStatus::PARTIAL, $invoice->fresh()->status);
    }

    public function test_item_scoped_refund_respects_line_cap_and_exposes_invoice_item_id(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PAID]);
        $itemA = $invoice->items()->create(['description' => 'Line A', 'price' => 50, 'quantity' => 1]);
        $itemB = $invoice->items()->create(['description' => 'Line B', 'price' => 50, 'quantity' => 1]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 35,
            'invoice_item_id' => $itemA->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.invoice_item_id', $itemA->id)
            ->assertJsonPath('data.amount', 35);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 20,
            'invoice_item_id' => $itemA->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors(['amount']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 15,
            'invoice_item_id' => $itemA->id,
        ]))->assertCreated();

        $this->assertEquals(50, (float) Refund::query()->where('invoice_item_id', $itemA->id)->sum('amount'));
        $this->assertDatabaseMissing('refunds', ['invoice_item_id' => $itemB->id]);
    }

    public function test_voided_item_can_receive_item_scoped_refund(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        [$invoice, $item] = $this->paidDoneInvoiceItem();

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
            'reason' => 'Treatment failed',
        ])->assertOk();

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 80,
            'invoice_item_id' => $item->id,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.invoice_item_id', $item->id);
    }

    public function test_deduct_only_invoice_cannot_be_refunded(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PARTIAL]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 0,
            'deduct_amount' => 50,
            'deduct_reason' => 'Staff discount',
        ]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 10]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice']);

        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_full_refund_when_total_exceeds_gross_paid_is_unpaid_not_refunded(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::PARTIAL]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 80]))
            ->assertCreated();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::UNPAID, $invoice->status);
        $this->assertGreaterThan(0, $invoice->remaining_amount);
        $this->assertNotSame(InvoiceStatus::REFUNDED, $invoice->status);
    }

    public function test_refunded_status_only_when_total_is_zero_and_cash_fully_returned(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        [$invoice, $item] = $this->paidDoneInvoiceItem();

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
            'reason' => 'Remove charge',
        ])->assertOk();

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 80,
            'invoice_item_id' => $item->id,
        ]))->assertCreated();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->status);
        $this->assertEquals(0, $invoice->remaining_amount);
    }

    public function test_refund_after_void_clears_refund_due(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        [$invoice, $item] = $this->paidDoneInvoiceItem();

        $this->postJson("/api/v1/invoice-items/{$item->id}/void", [
            'reason' => 'Remove charge',
        ])->assertOk();

        $fresh = $invoice->fresh();
        $this->assertSame('80.00', $fresh->balance()['refund_due']);
        $this->assertTrue($fresh->balance()['has_refund_due']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 80,
            'invoice_item_id' => $item->id,
        ]))->assertCreated();

        $after = $invoice->fresh();
        $this->assertSame('0.00', $after->balance()['refund_due']);
        $this->assertFalse($after->balance()['has_refund_due']);
    }

    #[Group('concurrency')]
    public function test_back_to_back_refunds_cannot_exceed_net_paid_under_row_lock(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 60]))
            ->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 60,
            'reason_code' => RefundReasonCode::INCORRECT_PAYMENT->value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseCount('refunds', 1);
        $this->assertEquals(60, (float) Refund::query()->where('invoice_id', $invoice->id)->sum('amount'));
    }

    public function test_cancelled_invoice_cannot_be_refunded(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::CANCELLED]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload())
            ->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(InvoiceStatus::CANCELLED, $invoice->fresh()->status);
    }

    public function test_unpaid_invoice_cannot_be_refunded(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::UNPAID]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload())
            ->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(InvoiceStatus::UNPAID, $invoice->fresh()->status);
    }

    public function test_second_refund_rejected_when_net_paid_is_exhausted(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 100]))
            ->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload([
            'amount' => 10,
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['invoice']);

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_missing_amount_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_missing_reason_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", ['amount' => 50])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason_code']);
    }

    public function test_invalid_reason_key_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'amount' => 50,
            'reason_code' => 'made_up_reason',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason_code']);
    }

    public function test_other_reason_requires_a_non_whitespace_note(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'amount' => 50,
            'reason_code' => RefundReasonCode::OTHER->value,
            'reason_note' => '   ',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason_note']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'amount' => 50,
            'reason_code' => RefundReasonCode::OTHER->value,
            'reason_note' => 'Typed in error at reception',
        ])->assertCreated()->assertJsonPath('data.reason_note', 'Typed in error at reception');
    }

    public function test_reason_note_is_ignored_unless_reason_is_other(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'amount' => 50,
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
            'reason_note' => 'should be discarded',
        ])->assertCreated()->assertJsonPath('data.reason_note', null);
    }

    public function test_assistant_cannot_create_or_list_refunds(): void
    {
        $this->actingAsTenantUser(role: UserRole::ASSISTANT);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 50]))
            ->assertForbidden();

        $this->getJson('/api/v1/refunds')->assertForbidden();
    }

    public function test_full_cash_refund_reopens_balance_and_allows_new_payment(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 100]))
            ->assertCreated();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::UNPAID, $invoice->status);
        $this->assertEquals(100, $invoice->remaining_amount);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 10,
            'method' => 'cash',
        ])->assertCreated();

        $this->assertSame(InvoiceStatus::PARTIAL, $invoice->fresh()->status);
        $this->assertEquals(90, $invoice->fresh()->remaining_amount);
    }

    public function test_recalculate_status_derives_status_from_ledger_not_refunded_label(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->refunded()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        app(InvoiceService::class)->recalculateStatus($invoice);

        $this->assertSame(InvoiceStatus::PAID, $invoice->fresh()->status);
    }

    public function test_patient_summary_reflects_ledger_remaining_after_refund(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 200,
            'status' => InvoiceStatus::PARTIAL,
        ]);
        $patientId = $invoice->patient_id;

        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 50]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 50]))
            ->assertCreated();

        $summary = $this->getJson("/api/v1/patients/{$patientId}/invoices-summary")->assertOk();
        $this->assertEquals(200, $summary->json('data.total_remaining'));
        $this->assertEquals(50, $summary->json('data.total_refunded'));
        $this->assertSame($user->id, Refund::query()->first()->refunded_by);
    }

    public function test_refunds_list_is_paginated_and_filterable(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 80, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80, 'method' => 'cash']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'amount' => 80,
            'reason_code' => RefundReasonCode::OTHER->value,
            'reason_note' => 'Front desk duplicate posting',
        ])->assertCreated();

        $this->getJson('/api/v1/refunds?reason_code=other')
            ->assertOk()
            ->assertJsonPath('data.summary.total_count', 1)
            ->assertJsonPath('data.summary.total_amount', 80)
            ->assertJsonPath('data.items.0.reason_note', 'Front desk duplicate posting')
            ->assertJsonPath('data.items.0.refunded_by', $user->id);

        $other = Invoice::factory()->create(['total_amount' => 40, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $other->id, 'amount' => 40]);
        $this->postJson("/api/v1/invoices/{$other->id}/refunds", $this->refundPayload(['amount' => 40]))
            ->assertCreated();

        $this->getJson('/api/v1/refunds?patient_id='.$invoice->patient_id)
            ->assertOk()
            ->assertJsonPath('data.summary.total_count', 1)
            ->assertJsonPath('data.items.0.invoice_id', $invoice->id);
    }

    public function test_refund_is_counted_on_refunded_at_not_payment_day(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 90, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 90,
            'created_at' => now()->subMonths(2),
            'paid_at' => now()->subMonths(2),
        ]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 90]))
            ->assertCreated();

        $analytics = $this->getJson('/api/v1/dashboard/financial-analytics')->assertOk();
        $this->assertEquals(90, $analytics->json('data.kpis.total_refunded_mtd'));
        $this->assertEquals(1, $analytics->json('data.kpis.refunds_count_mtd'));

        $income = $this->getJson('/api/v1/dashboard/income-expense?range=this_month')->assertOk();
        $this->assertEquals(90, $income->json('data.total_refunded'));
        $this->assertEquals(-90, $income->json('data.total_income'));
        $currentPeriod = now()->format('M Y');
        $point = collect($income->json('data.series'))->firstWhere('period', $currentPeriod);
        $this->assertNotNull($point);
        $this->assertEquals(90, $point['refunds']);
    }

    public function test_refund_does_not_change_treatment_fields(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $item = $invoice->items()->create([
            'description' => 'Linked treatment line',
            'price' => 100,
            'quantity' => 1,
        ]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", $this->refundPayload(['amount' => 100]))
            ->assertCreated();

        $this->assertDatabaseHas('invoice_items', [
            'id' => $item->id,
            'price' => 100,
            'quantity' => 1,
            'description' => 'Linked treatment line',
        ]);
        $this->assertSame(InvoiceStatus::UNPAID, $invoice->fresh()->status);
    }

    /**
     * @return array{0: Invoice, 1: InvoiceItem}
     */
    private function paidDoneInvoiceItem(): array
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
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80]);
        $invoice->update(['status' => InvoiceStatus::PAID]);

        return [$invoice, $item];
    }
}
