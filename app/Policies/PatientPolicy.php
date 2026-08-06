<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('patient.view');
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && $user->can('patient.view');
    }

    public function create(User $user): bool
    {
        return $user->can('patient.create');
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && $user->can('patient.update');
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->tenant_id === $patient->tenant_id
            && $user->can('patient.delete');
    }
}
