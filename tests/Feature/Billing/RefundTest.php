<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentType;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefundTest extends TestCase
{
    public function test_refund_creates_a_positive_refund_row(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        [$invoice, $item] = $this->paidInvoice(100, 100);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 25,
            'reason' => 'duplicate_payment',
            'method' => 'cash',
        ])->assertCreated()
            ->assertJsonPath('data.amount', 25)
            ->assertJsonPath('data.reason_code', 'duplicate_payment')
            ->assertJsonPath('data.invoice_item_id', $item->id);

        $refund = Payment::query()->where('type', PaymentType::Refund->value)->first();
        $this->assertNotNull($refund);
        $this->assertSame('25.00', (string) $refund->amount);
        $this->assertSame(0.0, (float) $refund->deduct_amount);
        $this->assertDatabaseMissing('payments', [
            'invoice_id' => $invoice->id,
            'type' => PaymentType::Payment->value,
            'amount' => -25,
        ]);

        $invoice->refresh();
        $this->assertGreaterThanOrEqual(0, $invoice->netPaid());
        $this->assertGreaterThanOrEqual(0, (float) $invoice->remaining_amount);
        $this->assertSame(InvoiceStatus::PARTIAL, $invoice->status);
    }

    public function test_refund_above_net_paid_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        [$invoice, $item] = $this->paidInvoice(100, 40);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 41,
            'reason' => 'incorrect_payment',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(0, Payment::query()->where('type', PaymentType::Refund->value)->count());
    }

    public function test_refund_above_the_item_remaining_amount_is_rejected(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::UNPAID]);
        $first = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'price' => 60,
            'quantity' => 1,
        ]);
        InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'price' => 40,
            'quantity' => 1,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $first->id,
            'amount' => 50,
            'reason' => 'patient_dissatisfied',
        ])->assertCreated();

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $first->id,
            'amount' => 20,
            'reason' => 'patient_dissatisfied',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertSame(1, Payment::query()->where('type', PaymentType::Refund->value)->count());
    }

    public function test_refund_requires_a_reason(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        [$invoice, $item] = $this->paidInvoice(80, 80);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 10,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_user_without_payment_refund_gets_403(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        [$invoice, $item] = $this->paidInvoice(80, 80);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 10,
            'reason' => 'other',
            'notes' => 'Asked at the desk',
        ])->assertForbidden();

        $this->getJson('/api/v1/refunds')->assertForbidden();
    }

    /**
     * The refund is re-checked after lockForUpdate, so a second refund cannot pass the cap once the first has committed.
     *
     * A real parallel test (two connections racing on the same invoice) must be run once on Linux/CI before release.
     * This Windows PHPUnit process cannot interleave two open transactions without hanging on the row lock.
     */
    public function test_second_refund_cannot_exceed_the_cap_after_the_invoice_is_locked(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        [$invoice, $item] = $this->paidInvoice(100, 30);

        $lockedInvoice = false;
        DB::listen(function ($query) use (&$lockedInvoice) {
            $sql = strtolower($query->sql);
            if (str_contains($sql, 'invoices') && str_contains($sql, 'for update')) {
                $lockedInvoice = true;
            }
        });

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 30,
            'reason' => 'duplicate_payment',
        ])->assertCreated();

        $this->assertTrue($lockedInvoice, 'Refund flow must lock the invoice row with lockForUpdate.');

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 0.01,
            'reason' => 'duplicate_payment',
        ])->assertUnprocessable();

        $refunded = (float) Payment::query()->where('type', PaymentType::Refund->value)->sum('amount');
        $this->assertEquals(30.0, $refunded);
        $invoice->refresh();
        $this->assertEquals(0.0, $invoice->netPaid());
        // Cash returned on a partly paid invoice restores that portion of the debt.
        $this->assertSame(InvoiceStatus::UNPAID, $invoice->status);
        $this->assertEquals(100.0, (float) $invoice->remaining_amount);
    }

    public function test_status_is_refunded_only_when_remaining_is_zero_and_all_cash_was_returned(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        [$invoice, $item] = $this->paidInvoice(100, 100);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 40,
            'reason' => 'treatment_cancelled_by_patient',
        ])->assertCreated();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PARTIAL, $invoice->status);
        $this->assertEquals(60.0, $invoice->netPaid());
        $this->assertEquals(40.0, (float) $invoice->remaining_amount);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 60,
            'reason' => 'treatment_cancelled_by_patient',
        ])->assertCreated();

        $invoice->refresh();
        $this->assertEquals(0.0, $invoice->netPaid());
        // Returning all cash does not forgive the invoice; remaining is the total again.
        $this->assertSame(InvoiceStatus::UNPAID, $invoice->status);
        $this->assertEquals(100.0, (float) $invoice->remaining_amount);

        $this->postJson("/api/v1/invoices/{$invoice->id}/payments", [
            'amount' => 0,
            'deduct_amount' => 100,
            'deduct_reason' => 'Treatment cancelled',
            'method' => 'cash',
        ])->assertCreated();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->status);
        $this->assertEquals(0.0, $invoice->netPaid());
        $this->assertEquals(0.0, (float) $invoice->remaining_amount);
    }

    public function test_zero_total_invoice_is_paid(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 0,
            'status' => InvoiceStatus::PARTIAL,
        ]);

        app(InvoiceService::class)->recalculateStatus($invoice);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::PAID, $invoice->status);
        $this->assertEquals(0.0, (float) $invoice->remaining_amount);
        $this->assertGreaterThanOrEqual(0, $invoice->netPaid());
    }

    public function test_zero_total_invoice_with_refunds_and_zero_net_paid_is_refunded(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => 0,
            'status' => InvoiceStatus::PARTIAL,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'price' => 0,
            'quantity' => 1,
        ]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 40,
        ]);
        Payment::factory()->refund()->create([
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $item->id,
            'amount' => 40,
        ]);

        app(InvoiceService::class)->recalculateStatus($invoice);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::REFUNDED, $invoice->status);
        $this->assertEquals(0.0, $invoice->netPaid());
        $this->assertEquals(0.0, (float) $invoice->remaining_amount);
    }

    public function test_recalculate_preserves_cancelled_and_balances_never_go_negative(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $cancelled = Invoice::factory()->create([
            'total_amount' => 100,
            'status' => InvoiceStatus::CANCELLED,
        ]);
        Payment::factory()->create(['invoice_id' => $cancelled->id, 'amount' => 100]);

        app(InvoiceService::class)->recalculateStatus($cancelled);
        $this->assertSame(InvoiceStatus::CANCELLED, $cancelled->fresh()->status);

        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::PAID]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'price' => 100,
            'quantity' => 1,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 10]);
        Payment::factory()->refund()->create([
            'invoice_id' => $invoice->id,
            'invoice_item_id' => $item->id,
            'amount' => 50,
        ]);

        $invoice->refresh();
        $this->assertEquals(0.0, $invoice->netPaid());
        $this->assertGreaterThanOrEqual(0, (float) $invoice->remaining_amount);

        $payload = $this->getJson("/api/v1/invoices/{$invoice->id}")->assertOk()->json('data');
        $this->assertGreaterThanOrEqual(0, (float) $payload['net_paid']);
        $this->assertGreaterThanOrEqual(0, (float) $payload['remaining_amount']);
        $this->assertGreaterThanOrEqual(0, (float) $payload['refunded_amount']);
    }

    public function test_deduct_amount_does_not_raise_the_refund_cap_or_link_to_an_item(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::UNPAID]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'price' => 100,
            'quantity' => 1,
        ]);
        $payment = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 40,
            'deduct_amount' => 60,
            'deduct_reason' => 'Courtesy',
        ]);

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 41,
            'reason' => 'duplicate_payment',
        ])->assertUnprocessable();

        $this->postJson("/api/v1/invoices/{$invoice->id}/refunds", [
            'invoice_item_id' => $item->id,
            'amount' => 40,
            'reason' => 'duplicate_payment',
            'refunded_payment_id' => $payment->id,
        ])->assertCreated();

        $payment->refresh();
        $refund = Payment::query()->where('type', PaymentType::Refund->value)->first();

        $this->assertNull($payment->invoice_item_id);
        $this->assertEquals(60.0, (float) $payment->deduct_amount);
        $this->assertSame($item->id, $refund->invoice_item_id);
        $this->assertSame($payment->id, $refund->refunded_payment_id);
        $this->assertEquals(0.0, (float) $refund->deduct_amount);
        $this->assertNotSame(InvoiceStatus::REFUNDED, $invoice->fresh()->status);
    }

    /**
     * @return array{0: Invoice, 1: InvoiceItem}
     */
    private function paidInvoice(float $total, float $paid): array
    {
        $invoice = Invoice::factory()->create([
            'total_amount' => $total,
            'status' => InvoiceStatus::UNPAID,
        ]);
        $item = InvoiceItem::factory()->create([
            'invoice_id' => $invoice->id,
            'price' => $total,
            'quantity' => 1,
        ]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $paid,
        ]);

        return [$invoice, $item];
    }
}
