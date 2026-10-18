<?php

namespace App\Support\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for invoice paid/remaining amounts and derived open status.
 */
final class InvoiceFinancials
{
    public static function paidAmount(Invoice $invoice): float
    {
        if ($invoice->relationLoaded('payments')) {
            return round((float) $invoice->payments->sum('amount'), 2);
        }

        $attributes = $invoice->getAttributes();
        if (array_key_exists('paid_amount', $attributes)) {
            return round((float) $attributes['paid_amount'], 2);
        }

        return round((float) $invoice->payments()->sum('amount'), 2);
    }

    public static function deductedAmount(Invoice $invoice): float
    {
        if ($invoice->relationLoaded('payments')) {
            return round((float) $invoice->payments->sum(
                fn (Payment $payment) => (float) ($payment->deduct_amount ?? 0)
            ), 2);
        }

        $attributes = $invoice->getAttributes();
        if (array_key_exists('deducted_amount', $attributes)) {
            return round((float) $attributes['deducted_amount'], 2);
        }

        return round((float) $invoice->payments()->sum('deduct_amount'), 2);
    }

    public static function appliedCredits(Invoice $invoice): float
    {
        return round(self::paidAmount($invoice) + self::deductedAmount($invoice), 2);
    }

    public static function refundedAmount(Invoice $invoice): float
    {
        if ($invoice->relationLoaded('refunds')) {
            return round((float) $invoice->refunds->sum('amount'), 2);
        }

        $attributes = $invoice->getAttributes();
        if (array_key_exists('refunded_amount', $attributes)) {
            return round((float) $attributes['refunded_amount'], 2);
        }

        return round((float) $invoice->refunds()->sum('amount'), 2);
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

        return max(0.0, round((float) $invoice->total_amount - self::appliedCredits($invoice), 2));
    }

    public static function deriveOpenStatus(Invoice $invoice): InvoiceStatus
    {
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
}
