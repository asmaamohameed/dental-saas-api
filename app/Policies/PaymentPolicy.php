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
        if ($user->isOwner()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id
            && $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ]);
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->tenant_id === $payment->invoice->tenant_id
            && $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ]);
    }

    public function create(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id
            && $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]);
    }

    /**
     * Owner is allowed via before(). Doctor is allowed here. Assistant and receptionist are not.
     */
    public function refund(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id
            && $user->hasAnyClinicRole([
                UserRole::DOCTOR,
            ]);
    }

    public function viewAnyRefund(User $user): bool
    {
        return $user->hasAnyClinicRole([
            UserRole::DOCTOR,
        ]);
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
