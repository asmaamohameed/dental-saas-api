<?php

namespace App\Billing\Domain;

use App\Enums\InvoiceStatus;
use InvalidArgumentException;

/**
 * Immutable money snapshot. This is the only place invoice balance formulas live.
 */
final readonly class InvoiceBalance
{
    private function __construct(
        public string $grossPaid,
        public string $refunded,
        public string $netPaid,
        public string $deducted,
        public string $settled,
        public string $total,
        public string $remaining,
        public string $refundDue,
        public ?string $performedTotal,
        public ?string $duePerformed,
        public bool $hasRefundDue,
    ) {}

    /**
     * Build a snapshot from stored sums.
     * Pass performedTotal only when item rows were actually read; null means it was not asked for.
     */
    public static function fromRawSums(
        string $grossPaid,
        string $refunded,
        string $deducted,
        string $total,
        ?string $performedTotal = null,
    ): self {
        $grossPaid = self::money($grossPaid);
        $refunded = self::money($refunded);
        $deducted = self::money($deducted);
        $total = self::money($total);

        $netPaid = self::maxZero(bcsub($grossPaid, $refunded, 2));
        $settled = bcadd($netPaid, $deducted, 2);
        $remaining = self::maxZero(bcsub($total, $settled, 2));
        $excess = self::maxZero(bcsub($settled, $total, 2));
        $refundDue = bccomp($netPaid, $excess, 2) === -1 ? $netPaid : $excess;

        $duePerformed = null;
        if ($performedTotal !== null) {
            $performedTotal = self::money($performedTotal);
            $duePerformed = self::maxZero(bcsub($performedTotal, $settled, 2));
        }

        return new self(
            grossPaid: $grossPaid,
            refunded: $refunded,
            netPaid: $netPaid,
            deducted: $deducted,
            settled: $settled,
            total: $total,
            remaining: $remaining,
            refundDue: $refundDue,
            performedTotal: $performedTotal,
            duePerformed: $duePerformed,
            hasRefundDue: bccomp($refundDue, '0', 2) === 1,
        );
    }

    /**
     * Money precedence. Cancelled is not a money status: recalculateStatus returns
     * before calling this, so a cancelled invoice is never overwritten.
     *
     * Refund rows cannot store a zero amount, so a positive refunded sum means refunds exist.
     */
    public function status(): InvoiceStatus
    {
        $refundsExist = bccomp($this->refunded, '0', 2) === 1;

        if ($refundsExist && bccomp($this->netPaid, '0', 2) === 0 && bccomp($this->remaining, '0', 2) === 0) {
            return InvoiceStatus::REFUNDED;
        }

        if (bccomp($this->remaining, '0', 2) === 0) {
            return InvoiceStatus::PAID;
        }

        if (bccomp($this->settled, '0', 2) === 1) {
            return InvoiceStatus::PARTIAL;
        }

        return InvoiceStatus::UNPAID;
    }

    /**
     * @return array{
     *     gross_paid: string,
     *     refunded: string,
     *     deducted: string,
     *     net_paid: string,
     *     settled: string,
     *     total: string,
     *     remaining: string,
     *     refund_due: string,
     *     has_refund_due: bool,
     *     performed_total?: string,
     *     due_performed?: string
     * }
     */
    public function toArray(): array
    {
        $values = [
            'gross_paid' => $this->grossPaid,
            'refunded' => $this->refunded,
            'deducted' => $this->deducted,
            'net_paid' => $this->netPaid,
            'settled' => $this->settled,
            'total' => $this->total,
            'remaining' => $this->remaining,
            'refund_due' => $this->refundDue,
            'has_refund_due' => $this->hasRefundDue,
        ];

        if ($this->performedTotal !== null && $this->duePerformed !== null) {
            $values['performed_total'] = $this->performedTotal;
            $values['due_performed'] = $this->duePerformed;
        }

        return $values;
    }

    private static function money(string $value): string
    {
        if ($value === '' || ! is_numeric($value)) {
            throw new InvalidArgumentException('Invoice balance amounts must be numeric.');
        }

        return bcadd($value, '0', 2);
    }

    private static function maxZero(string $value): string
    {
        return bccomp($value, '0', 2) === -1 ? '0.00' : $value;
    }
}
