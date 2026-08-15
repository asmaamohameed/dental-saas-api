<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
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
        if ($user->role === UserRole::RECEPTIONIST && $invoice->status === InvoiceStatus::PARTIAL) {
            return false;
        }

        return true;
    }
}
