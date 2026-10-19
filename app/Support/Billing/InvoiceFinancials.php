<?php

namespace App\Support\Billing;

use App\Billing\Domain\Exceptions\RefundsExceedRecordedCashException;
use App\Billing\Domain\InvoiceBalance;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;

/**
 * API and service-facing invoice money helpers. Ledger math lives in InvoiceBalance;
 * snapshots use stored total_amount and fresh payment/refund sums (not Invoice balance cache).
 */
final class InvoiceFinancials
{
    public static function paidAmount(Invoice $invoice): float
    {
        return (float) self::snapshot($invoice)->grossPaid;
    }

    public static function deductedAmount(Invoice $invoice): float
    {
        return (float) self::snapshot($invoice)->deducted;
    }

    public static function refundedAmount(Invoice $invoice): float
    {
        return (float) self::snapshot($invoice)->refunded;
    }

    public static function netPaid(Invoice $invoice): float
    {
        return (float) self::snapshot($invoice)->netPaid;
    }

    public static function refundDue(Invoice $invoice): float
    {
        return (float) self::snapshot($invoice)->refundDue;
    }

    public static function settledAmount(Invoice $invoice): float
    {
        return (float) self::snapshot($invoice)->settled;
    }

    /**
     * Settled amount applied to the invoice (net paid cash + deductions).
     */
    public static function appliedCredits(Invoice $invoice): float
    {
        return self::settledAmount($invoice);
    }

    public static function isClosed(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::CANCELLED;
    }

    public static function remainingAmount(Invoice $invoice): float
    {
        if ($invoice->status === InvoiceStatus::CANCELLED) {
            return 0.0;
        }

        return (float) self::snapshot($invoice)->remaining;
    }

    public static function deriveOpenStatus(Invoice $invoice): InvoiceStatus
    {
        return self::snapshot($invoice)->status();
    }

    public static function isRefundable(Invoice $invoice): bool
    {
        if ($invoice->status === InvoiceStatus::CANCELLED) {
            return false;
        }

        return bccomp((string) self::netPaid($invoice), '0', 2) === 1;
    }

    public static function defaultRefundMethod(Invoice $invoice): string
    {
        $payments = $invoice->relationLoaded('payments')
            ? $invoice->payments
            : $invoice->payments()->orderBy('paid_at')->orderBy('created_at')->get();

        if ($payments->isEmpty()) {
            return 'cash';
        }

        $largest = $payments->sortByDesc(fn (Payment $payment) => (float) $payment->amount)->first();

        return $largest?->method ?: 'cash';
    }

    public static function assertMutable(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'invoice' => 'A cancelled invoice cannot be modified.',
            ]);
        }
    }

    public static function grossPaidSum(Invoice $invoice): string
    {
        return self::aggregate($invoice, 'payments', 'paid_amount', 'amount');
    }

    public static function refundedSum(Invoice $invoice): string
    {
        return self::aggregate($invoice, 'refunds', 'refunded_amount', 'amount');
    }

    /**
     * @throws RefundsExceedRecordedCashException
     */
    public static function assertGrossPaidSupportsRefunds(string $grossPaidAfter, string $refundedTotal): void
    {
        $grossPaidAfter = self::money($grossPaidAfter);
        $refundedTotal = self::money($refundedTotal);

        if (bccomp($grossPaidAfter, $refundedTotal, 2) === -1) {
            throw RefundsExceedRecordedCashException::forGrossBelowRefunded($refundedTotal, $grossPaidAfter);
        }
    }

    public static function assertGrossPaidWithinInvoiceTotal(string $grossPaidAfter, string $invoiceTotal): void
    {
        $grossPaidAfter = self::money($grossPaidAfter);
        $invoiceTotal = self::money($invoiceTotal);

        if (bccomp($grossPaidAfter, $invoiceTotal, 2) === 1) {
            throw ValidationException::withMessages([
                'amount' => "Recorded payments ({$grossPaidAfter}) cannot exceed the invoice total ({$invoiceTotal}).",
            ]);
        }
    }

    private static function snapshot(Invoice $invoice): InvoiceBalance
    {
        return InvoiceBalance::fromRawSums(
            self::aggregate($invoice, 'payments', 'paid_amount', 'amount'),
            self::aggregate($invoice, 'refunds', 'refunded_amount', 'amount'),
            self::aggregate($invoice, 'payments', 'deducted_amount', 'deduct_amount'),
            (string) $invoice->total_amount,
        );
    }

    private static function aggregate(Invoice $invoice, string $relation, string $attribute, string $column): string
    {
        if ($invoice->relationLoaded($relation)) {
            $sum = '0.00';

            foreach ($invoice->getRelation($relation) as $row) {
                $sum = bcadd($sum, self::money($row->{$column} ?? 0), 2);
            }

            return $sum;
        }

        $attributes = $invoice->getAttributes();
        if (array_key_exists($attribute, $attributes)) {
            return self::money($attributes[$attribute]);
        }

        $query = $relation === 'refunds' ? $invoice->refunds() : $invoice->payments();

        return self::money($query->sum($column));
    }

    private static function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
    }
}
