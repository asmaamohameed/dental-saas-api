<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class ToothRecordPolicy
{
    public function viewAny(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && ($user->role?->isOwner() || $user->canAccessClinicalData());
    }

    public function create(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && ($user->role?->isOwner() || $user->canAccessClinicalData());
    }
}
