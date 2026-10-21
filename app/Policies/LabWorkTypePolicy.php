<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\LabWorkType;
use App\Models\User;

class LabWorkTypePolicy
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
        return $this->canView($user);
    }

    public function create(User $user): bool
    {
        return $user->clinicCan('labWork:create', $user->hasAnyClinicRole([
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ]));
    }

    public function delete(User $user, LabWorkType $labWorkType): bool
    {
        return $user->tenant_id === $labWorkType->tenant_id
            && $user->clinicCan('labWork:create', $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]));
    }

    private function canView(User $user): bool
    {
        return $user->clinicCan('labWork:view', $user->hasAnyClinicRole([
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ]));
    }
}
