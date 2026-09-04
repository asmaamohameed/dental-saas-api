<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;

class ToothRecordPolicy
{
    public function viewAny(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && in_array($user->role, [UserRole::OWNER, UserRole::DOCTOR]);
    }

    public function create(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && in_array($user->role, [UserRole::OWNER, UserRole::DOCTOR]);
    }
}
