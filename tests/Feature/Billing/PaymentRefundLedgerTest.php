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
            ->assertJsonPath('message', __('billing.refunds_exceed_recorded_cash', [
                'gross_paid' => '650.00',
                'refunded' => '700.00',
            ]));

        $this->assertDatabaseHas('payments', ['id' => $second->id, 'amount' => 400]);
    }

    public function test_deleting_payment_below_refund_floor_is_rejected(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 700);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', __('billing.refunds_exceed_recorded_cash', [
                'gross_paid' => '600.00',
                'refunded' => '700.00',
            ]));

        $this->assertDatabaseHas('payments', ['id' => $second->id]);
    }

    public function test_updating_second_payment_to_200_within_ledger_cap_is_allowed(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}", [
            'amount' => 200,
        ])->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $second->id, 'amount' => 200]);
    }

    public function test_updating_second_payment_to_ledger_cap_is_allowed(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}", [
            'amount' => 700,
        ])->assertOk();

        $this->assertDatabaseHas('payments', ['id' => $second->id, 'amount' => 700]);
    }

    public function test_updating_second_payment_above_ledger_cap_is_rejected(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}", [
            'amount' => 701,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseHas('payments', ['id' => $second->id, 'amount' => 400]);
    }

    public function test_updating_payment_amount_plus_deduct_at_ledger_cap_is_allowed(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}", [
            'amount' => 500,
            'deduct_amount' => 200,
            'deduct_reason' => 'Courtesy adjustment',
        ])->assertOk();

        $this->assertDatabaseHas('payments', [
            'id' => $second->id,
            'amount' => 500,
            'deduct_amount' => 200,
        ]);
    }

    public function test_updating_payment_amount_plus_deduct_above_ledger_cap_is_rejected(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->putJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}", [
            'amount' => 500,
            'deduct_amount' => 201,
            'deduct_reason' => 'Courtesy adjustment',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['amount']);

        $this->assertDatabaseHas('payments', ['id' => $second->id, 'amount' => 400]);
    }

    public function test_deleting_payment_within_ledger_cap_is_allowed(): void
    {
        ['invoice' => $invoice, 'second' => $second] = $this->invoiceWithTwoPayments(1000, 600, 400);
        $this->recordRefund($invoice, 300);

        $this->deleteJson("/api/v1/invoices/{$invoice->id}/payments/{$second->id}")
            ->assertOk();

        $this->assertSoftDeleted('payments', ['id' => $second->id]);
    }
}
