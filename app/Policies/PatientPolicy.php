<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role?->isOwner()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role?->isReceptionist() || $user->canAccessClinicalData();
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && ($user->role?->isReceptionist() || $user->canAccessClinicalData());
    }

    public function viewMedicalHistory(User $user): bool
    {
        return $user->canAccessClinicalData();
    }

    public function create(User $user): bool
    {
        return $user->role?->isOwner()
            || $user->role?->isReceptionist()
            || $user->canAccessClinicalData();
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && ($user->role?->isOwner()
                || $user->role?->isReceptionist()
                || $user->canAccessClinicalData());
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && $user->role === UserRole::OWNER;
    }
}
