<?php

namespace App\Billing\Domain\Exceptions;

final class CannotDeleteNonPlannedItem extends BillingDomainException
{
    public static function refused(): self
    {
        return new self('Only a planned line can be deleted.');
    }
}
