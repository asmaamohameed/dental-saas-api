<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;

class PaymentPolicy
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

    public function view(User $user, Payment $payment): bool
    {
        return $user->tenant_id === $payment->invoice->tenant_id
            && in_array($user->role, [
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ], true);
    }

    public function create(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id
            && $user->role === UserRole::RECEPTIONIST;
    }

    public function update(User $user, Payment $payment): bool
    {
        // Only the owner may edit a payment — granted via before().
        // Any non-owner reaching this point is explicitly denied.
        return false;
    }

    public function delete(User $user, Payment $payment): bool
    {
        // Only the owner may delete a payment — granted via before().
        return false;
    }
}
