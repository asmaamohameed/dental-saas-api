<?php

namespace App\Billing\Domain\Exceptions;

final class CannotVoidInvoiceItem extends BillingDomainException
{
    public static function notDone(): self
    {
        return new self('Only a completed line can be voided.');
    }
}
