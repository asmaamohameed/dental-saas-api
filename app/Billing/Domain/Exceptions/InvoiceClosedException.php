<?php

namespace App\Billing\Domain\Exceptions;

use App\Enums\InvoiceStatus;

final class InvoiceClosedException extends BillingDomainException
{
    public static function throwIf(InvoiceStatus $status): void
    {
        if ($status === InvoiceStatus::CANCELLED || $status === InvoiceStatus::REFUNDED) {
            throw new self('Cancelled or refunded invoices cannot be changed.');
        }
    }
}
