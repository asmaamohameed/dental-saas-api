<?php

namespace Tests\Unit\Support\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Billing\InvoiceFinancials;
use Tests\TestCase;

class InvoiceFinancialsTest extends TestCase
{
    public function test_refunded_invoice_has_zero_remaining_and_is_not_refundable(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->refunded()->create(['total_amount' => 200]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 50]);

        $fresh = $invoice->fresh(['payments', 'refunds']);

        $this->assertSame(0.0, InvoiceFinancials::remainingAmount($fresh));
        $this->assertFalse(InvoiceFinancials::isRefundable($fresh));
    }

    public function test_cancelled_invoice_has_zero_remaining_and_is_not_refundable(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->create([
            'total_amount' => 200,
            'status' => InvoiceStatus::CANCELLED,
        ]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 50]);

        $fresh = $invoice->fresh(['payments', 'refunds']);

        $this->assertSame(0.0, InvoiceFinancials::remainingAmount($fresh));
        $this->assertFalse(InvoiceFinancials::isRefundable($fresh));
    }

    public function test_refundable_uses_net_paid_not_deductions(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::PARTIAL]);
        Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => 0,
            'deduct_amount' => 50,
        ]);

        $fresh = $invoice->fresh();
        $this->assertSame(0.0, InvoiceFinancials::paidAmount($fresh));
        $this->assertFalse(InvoiceFinancials::isRefundable($fresh));
    }

    public function test_derive_open_status_from_settled_amount(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->create(['total_amount' => 100, 'status' => InvoiceStatus::UNPAID]);
        $this->assertSame(InvoiceStatus::UNPAID, InvoiceFinancials::deriveOpenStatus($invoice));

        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 40]);
        $this->assertSame(InvoiceStatus::PARTIAL, InvoiceFinancials::deriveOpenStatus($invoice->fresh(['payments'])));

        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 60]);
        $this->assertSame(InvoiceStatus::PAID, InvoiceFinancials::deriveOpenStatus($invoice->fresh(['payments'])));
    }

    public function test_derive_open_status_reflects_partial_refund(): void
    {
        $this->actingAsTenantUser();
        $invoice = Invoice::factory()->create(['total_amount' => 200, 'status' => InvoiceStatus::PAID]);
        Payment::factory()->create(['invoice_id' => $invoice->id, 'amount' => 100]);
        Refund::factory()->create(['invoice_id' => $invoice->id, 'amount' => 40]);

        $fresh = $invoice->fresh(['payments', 'refunds']);

        $this->assertSame(InvoiceStatus::PARTIAL, InvoiceFinancials::deriveOpenStatus($fresh));
    }
}
