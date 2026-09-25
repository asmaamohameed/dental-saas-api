<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role?->isOwner()) {
            return true;
        }

        return null;
    }

    public function updateItems(User $user, Invoice $invoice): bool
    {

        if (! $user->role?->isOwner() && ! $user->role?->isReceptionist()) {
            return false;
        }

        if ($user->role?->isReceptionist() && $invoice->status === InvoiceStatus::PARTIAL) {
            return false;
        }

        return true;
    }
}
