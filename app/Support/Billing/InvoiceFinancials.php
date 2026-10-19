<?php

namespace App\Support\Billing;

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

    public static function appliedCredits(Invoice $invoice): float
    {
        // TEMP shim until 6b: legacy uses gross cash + deduct, not settled (net + deduct).
        return round(self::paidAmount($invoice) + self::deductedAmount($invoice), 2);
    }

    public static function isClosed(Invoice $invoice): bool
    {
        return in_array($invoice->status, [InvoiceStatus::REFUNDED, InvoiceStatus::CANCELLED], true);
    }

    public static function isLocked(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::REFUNDED;
    }

    public static function remainingAmount(Invoice $invoice): float
    {
        if (self::isClosed($invoice)) {
            return 0.0;
        }

        // TEMP shim until 6b: ignores refunds in remaining; ledger uses snapshot remaining.
        return max(0.0, round((float) $invoice->total_amount - self::appliedCredits($invoice), 2));
    }

    public static function deriveOpenStatus(Invoice $invoice): InvoiceStatus
    {
        // TEMP shim until 6b: legacy status from gross+deduct applied credits, not InvoiceBalance::status().
        $applied = self::appliedCredits($invoice);
        $total = (float) $invoice->total_amount;

        return match (true) {
            $applied >= $total && $total > 0 => InvoiceStatus::PAID,
            $applied > 0 => InvoiceStatus::PARTIAL,
            default => InvoiceStatus::UNPAID,
        };
    }

    public static function isRefundable(Invoice $invoice): bool
    {
        if (self::isClosed($invoice)) {
            return false;
        }

        // TEMP shim until 6b: refundable when gross paid > 0; 6b will use netPaid.
        return bccomp((string) self::paidAmount($invoice), '0', 2) === 1;
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
        if (self::isLocked($invoice)) {
            throw ValidationException::withMessages([
                'invoice' => 'A refunded invoice cannot be modified.',
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
