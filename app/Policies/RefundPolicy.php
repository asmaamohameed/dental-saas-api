<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Refund;
use App\Models\User;

class RefundPolicy
{
    /**
     * @return list<UserRole>
     */
    public static function allowedRoles(): array
    {
        return [
            UserRole::OWNER,
            UserRole::DOCTOR,
            UserRole::RECEPTIONIST,
        ];
    }

    public function viewAny(User $user): bool
    {
        return $user->clinicCan('invoice:refund', $user->hasAnyClinicRole(self::allowedRoles()));
    }

    public function view(User $user, Refund $refund): bool
    {
        return $user->tenant_id === $refund->tenant_id
            && $user->clinicCan('invoice:refund', $user->hasAnyClinicRole(self::allowedRoles()));
    }

    public function create(User $user, Invoice $invoice): bool
    {
        return $user->tenant_id === $invoice->tenant_id
            && $user->clinicCan('invoice:refund', $user->hasAnyClinicRole(self::allowedRoles()));
    }

    public function update(User $user, Refund $refund): bool
    {
        return false;
    }

    public function delete(User $user, Refund $refund): bool
    {
        return false;
    }
}
