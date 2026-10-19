<?php

namespace App\Billing\Domain\Exceptions;

final class RefundsExceedRecordedCashException extends BillingDomainException
{
    public static function forGrossBelowRefunded(string $refunded, string $grossPaid): self
    {
        return new self(__('billing.refunds_exceed_recorded_cash', [
            'refunded' => $refunded,
            'gross_paid' => $grossPaid,
        ]));
    }
}
