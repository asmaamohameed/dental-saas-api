<?php

namespace Tests\Unit\Support\Billing;

use App\Billing\Domain\InvoiceBalance;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Billing\InvoiceFinancials;
use Tests\TestCase;

class InvoiceFinancialsLedgerSixATest extends TestCase
{
    public function test_partial_refund_on_a_paid_invoice_diverges_ledger_remaining_from_financials_remaining(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->create([
            'total_amount' => 200,
            'status' => InvoiceStatus::PAID,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);
        Refund::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 50,
        ]);

        $fresh = $invoice->fresh(['payments', 'refunds']);

        $this->assertSame(150.0, (float) $fresh->balance()['remaining']);
        $this->assertSame(150.0, InvoiceFinancials::remainingAmount($fresh));
    }

    public function test_net_paid_with_no_refund_equals_gross_paid(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->create(['total_amount' => 120]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 80]);

        $fresh = $invoice->fresh(['payments', 'refunds']);

        $this->assertSame(80.0, InvoiceFinancials::paidAmount($fresh));
        $this->assertSame(80.0, InvoiceFinancials::netPaid($fresh));
    }

    public function test_net_paid_with_partial_refund_subtracts_refunded_cash(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::PARTIAL]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);
        Refund::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 40,
        ]);

        $fresh = $invoice->fresh(['payments', 'refunds']);

        $this->assertSame(60.0, InvoiceFinancials::netPaid($fresh));
    }

    public function test_net_paid_clamps_to_zero_when_refunded_exceeds_gross_paid(): void
    {
        $balance = InvoiceBalance::fromRawSums('50', '80', '0', '100');

        $this->assertSame('0.00', $balance->netPaid);
    }

    public function test_net_paid_is_zero_on_deduct_only_invoice(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->create(['total_amount' => 100]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 0,
            'deduct_amount' => 50,
            'deduct_reason' => 'Staff discount',
        ]);

        $fresh = $invoice->fresh(['payments', 'refunds']);

        $this->assertSame(0.0, InvoiceFinancials::paidAmount($fresh));
        $this->assertSame(50.0, InvoiceFinancials::deductedAmount($fresh));
        $this->assertSame(0.0, InvoiceFinancials::netPaid($fresh));
    }

    public function test_refund_due_when_collected_cash_exceeds_stored_total(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->create(['total_amount' => 70, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);

        $fresh = $invoice->fresh(['payments', 'refunds']);

        $this->assertSame(30.0, InvoiceFinancials::refundDue($fresh));
        $this->assertTrue($fresh->balance()['has_refund_due']);
    }
}
