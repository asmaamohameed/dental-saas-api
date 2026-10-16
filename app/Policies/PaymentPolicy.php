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
            && $user->clinicCan('payment:view', $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ]));
    }

    public function view(User $user, Payment $payment): bool
    {
        return $user->tenant_id === $payment->invoice->tenant_id
            && $user->clinicCan('payment:view', $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ]));
    }

    public function create(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id
            && $user->clinicCan('payment:create', $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]));
    }

    public function update(User $user, Payment $payment): bool
    {
        if (! $user->clinicCan('payment:edit', false)) {
            return false;
        }

        return $user->tenant_id === $payment->invoice->tenant_id;
    }

    public function delete(User $user, Payment $payment): bool
    {
        if (! $user->clinicCan('payment:delete', false)) {
            return false;
        }

        return $user->tenant_id === $payment->invoice->tenant_id;
    }
}
