<?php

namespace App\Billing\Domain\Exceptions;

final class CannotMutateDoneItem extends BillingDomainException
{
    public static function locked(): self
    {
        return new self('A completed line cannot be deleted or changed. Void it or reprice it with a reason.');
    }
}
