<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\RefundReasonCode;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\InvoiceService;
use Tests\TestCase;

class RefundTest extends TestCase
{
    public function test_successful_refund_creates_record_and_locks_invoice_without_changing_payments(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::PARTIAL]);
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 80,
            'method' => 'card',
        ]);

        $response = $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertCreated();

        $response->assertJsonPath('data.amount', 80)
            ->assertJsonPath('data.reason_code', RefundReasonCode::DUPLICATE_PAYMENT->value)
            ->assertJsonPath('data.reason_note', null)
            ->assertJsonPath('data.method', 'card');

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->status);
        $this->assertEquals(0, $invoice->remaining_amount);
        $this->assertEquals(200, (float) $invoice->total_amount);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'amount' => 80,
            'method' => 'card',
        ]);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_refund_amount_is_paid_amount_not_invoice_total(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 500, 'status' => InvoiceStatus::PARTIAL]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 120, 'method' => 'cash']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::INCORRECT_PAYMENT->value,
            'amount' => 500,
        ])->assertCreated()->assertJsonPath('data.amount', 120);
    }

    public function test_cancelled_invoice_cannot_be_refunded(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::CANCELLED]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(InvoiceStatus::CANCELLED, $invoice->fresh()->status);
    }

    public function test_unpaid_invoice_cannot_be_refunded(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::UNPAID]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(InvoiceStatus::UNPAID, $invoice->fresh()->status);
    }

    public function test_already_refunded_invoice_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::PATIENT_DISSATISFIED->value,
        ])->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_missing_reason_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reason_code']);
    }

    public function test_invalid_reason_key_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => 'made_up_reason',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason_code']);
    }

    public function test_other_reason_requires_a_non_whitespace_note(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::OTHER->value,
            'reason_note' => '   ',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reason_note']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
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
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
            'reason_note' => 'should be discarded',
        ])->assertCreated()->assertJsonPath('data.reason_note', null);
    }

    public function test_assistant_cannot_create_or_list_refunds(): void
    {
        $this->actingAsTenantUser(role: UserRole::ASSISTANT);
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertForbidden();

        $this->getJson('/api/v1/refunds')->assertForbidden();
    }

    public function test_consecutive_clicks_do_not_create_two_refunds(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $invoice = Invoice::factory()->create(['total_amount' => 150, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 150]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::TREATMENT_CANCELLED_BY_PATIENT->value,
        ])->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::TREATMENT_CANCELLED_BY_PATIENT->value,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_refunded_invoice_rejects_payments_and_updates(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 10,
            'method' => 'cash',
        ])->assertUnprocessable();

        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'items' => [['price' => 80, 'description' => 'Changed after refund', 'quantity' => 1]],
        ])->assertUnprocessable();

        $this->deleteJson("/api/v1/invoices/{$invoice->id}")->assertUnprocessable();
    }

    public function test_recalculate_status_does_not_reopen_a_refunded_invoice(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->refunded()->create(['total_amount' => 100]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        app(InvoiceService::class)->recalculateStatus($invoice);

        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->fresh()->status);
    }

    public function test_patient_summary_excludes_refunded_invoice_from_remaining(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 200,
            'status' => InvoiceStatus::PARTIAL,
        ]);
        $patientId = $invoice->patient_id;

        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 50]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertCreated();

        $summary = $this->getJson("/api/v1/patients/{$patientId}/invoices-summary")->assertOk();
        $this->assertEquals(0, $summary->json('data.total_remaining'));
        $this->assertEquals(50, $summary->json('data.total_refunded'));
        $this->assertSame($user->id, Refund::query()->first()->refunded_by);
    }

    public function test_refunds_list_is_paginated_and_filterable(): void
    {
        $user = $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 80, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80, 'method' => 'cash']);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
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
        $this->postJson("/api/v1/invoices/{$other->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertCreated();

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

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::DUPLICATE_PAYMENT->value,
        ])->assertCreated();

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

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'reason_code' => RefundReasonCode::TREATMENT_CANCELLED_BY_CLINIC->value,
        ])->assertCreated();

        $this->assertDatabaseHas('invoice_items', [
            'id' => $item->id,
            'price' => 100,
            'quantity' => 1,
            'description' => 'Linked treatment line',
        ]);
        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->fresh()->status);
    }
}
