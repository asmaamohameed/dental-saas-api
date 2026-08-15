<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class InvoiceItemPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role?->isOwner()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id
            && in_array($user->role, [
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ], true);
    }

    public function create(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id
            && $user->role === UserRole::RECEPTIONIST
            && Gate::forUser($user)->allows('updateItems', $invoice);
    }

    public function update(User $user, InvoiceItem $invoiceItem): bool
    {
        return $user->tenant_id === $invoiceItem->invoice->tenant_id
            && $user->role === UserRole::RECEPTIONIST
            && Gate::forUser($user)->allows('updateItems', $invoiceItem->invoice);
    }

    public function delete(User $user, InvoiceItem $invoiceItem): bool
    {
        return $user->tenant_id === $invoiceItem->invoice->tenant_id
            && $user->role === UserRole::RECEPTIONIST
            && Gate::forUser($user)->allows('updateItems', $invoiceItem->invoice);
    }
}
