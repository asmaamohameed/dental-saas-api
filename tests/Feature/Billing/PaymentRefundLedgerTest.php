<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Services\InvoiceService;
use Tests\TestCase;

class PaymentRefundLedgerTest extends TestCase
{
    /**
     * @return array{invoice: Invoice, first: Payment, second: Payment}
     */
    private function invoiceWithTwoPayments(float $total, float $firstAmount, float $secondAmount): array
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $invoice = Invoice::factory()->create([
            'total_amount' => $total,
            'status' => InvoiceStatus::PAID,
        ]);
        $first = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $firstAmount,
        ]);
        $second = Payment::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $secondAmount,
        ]);

        return ['invoice' => $invoice, 'first' => $first, 'second' => $second];
    }

    private function recordRefund(Invoice $invoice, float $amount): void
    {
        Refund::factory()->create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
        ]);
        app(InvoiceService::class)->recalculateStatus($invoice->fresh(['payments', 'refunds']));
    }

    public function test_updating_payment_below_refund_floor_is_rejected(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 700);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}", [
            'amount' => 50,
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Recorded payments (650.00) would fall below refunds already issued (700.00).');

        $this->assertDatabaseHas('payments', ['id' => $second->id, 'amount' => 400]);
    }

    public function test_deleting_payment_below_refund_floor_is_rejected(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 700);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Recorded payments (600.00) would fall below refunds already issued (700.00).');

        $this->assertDatabaseHas('payments', ['id' => $second->id]);
    }

    public function test_updating_payment_to_ledger_cap_is_allowed(): void
    {
        ['invoice' => $invoice, 'first' => $first] = $this->invoiceWithTwoPayments(700, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$first->id}", [
            'amount' => 300,
        ])->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $first->id, 'amount' => 300]);
    }

    public function test_updating_payment_above_ledger_cap_is_rejected(): void
    {
        ['invoice' => $invoice, 'first' => $first] = $this->invoiceWithTwoPayments(700, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$first->id}", [
            'amount' => 301,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseHas('payments', ['id' => $first->id, 'amount' => 600]);
    }

    public function test_deleting_payment_within_ledger_cap_is_allowed(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}")
            ->assertOk();

        $this->assertSoftDeleted('payments', ['id' => $second->id]);
    }

    public function test_updating_deduct_amount_respects_refund_floor_and_ledger_cap(): void
    {
        ['invoice' => $invoice, 'first' => $first] = $this->invoiceWithTwoPayments(700, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$first->id}", [
            'amount' => 250,
            'deduct_amount' => 50,
            'deduct_reason' => 'Courtesy adjustment',
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $first->id,
            'amount' => 250,
            'deduct_amount' => 50,
        ]);
    }
}
