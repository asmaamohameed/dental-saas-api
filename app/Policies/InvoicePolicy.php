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
        if ($user->isOwner()) {
            return true;
        }

        return null;
    }

    public function updateItems(User $user, Invoice $invoice): bool
    {

        if (! $user->hasAnyClinicRole([
            UserRole::OWNER,
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ])) {
            return false;
        }

        if (! $user->isOwner() && $invoice->status === InvoiceStatus::PARTIAL) {
            return false;
        }

        return true;
    }
}
