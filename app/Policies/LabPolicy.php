<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Lab;
use App\Models\User;

class LabPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->isOwner()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user): bool
    {
        return $user->clinicCan('lab:view', $user->hasAnyClinicRole([
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ]));
    }

    public function view(User $user, Lab $lab): bool
    {
        return $user->tenant_id === $lab->tenant_id
            && $user->clinicCan('lab:view', $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]));
    }

    public function create(User $user): bool
    {
        return $user->clinicCan('lab:create', $user->isReceptionist());
    }

    public function manage(User $user): bool
    {
        return $user->clinicCan('lab:edit', $user->isReceptionist());
    }

    public function update(User $user, Lab $lab): bool
    {
        return $user->tenant_id === $lab->tenant_id
            && $user->clinicCan('lab:edit', $user->isReceptionist());
    }

    public function delete(User $user, Lab $lab): bool
    {
        return $user->tenant_id === $lab->tenant_id
            && $user->clinicCan('lab:delete', $user->isReceptionist());
    }
}
