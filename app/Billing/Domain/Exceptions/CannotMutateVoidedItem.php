<?php

namespace App\Billing\Domain\Exceptions;

final class CannotMutateVoidedItem extends BillingDomainException
{
    public static function locked(): self
    {
        return new self('A voided line cannot be changed.');
    }
}
