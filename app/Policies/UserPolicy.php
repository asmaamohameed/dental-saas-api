<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->clinicCan('staff:view', $user->isOwner());
    }

    public function create(User $user): bool
    {
        return $user->clinicCan('staff:create', $user->isOwner());
    }

    public function update(User $user, User $target): bool
    {
        return $this->canEditStaff($user, $target, 'staff:edit');
    }

    public function toggleActive(User $user, User $target): bool
    {
        return $this->canEditStaff($user, $target, 'staff:toggleActive');
    }

    private function canEditStaff(User $user, User $target, string $permission): bool
    {
        if ($user->tenant_id === null || $user->tenant_id !== $target->tenant_id) {
            return false;
        }

        if ($target->isOwner() && $user->id !== $target->id) {
            return false;
        }

        $actor = $user->currentClinicMembership();
        $subject = $target->currentClinicMembership();

        $roleAllows = $actor && $subject
            ? $actor->canManage($subject)
            : $user->isOwner() && ! $target->isOwner();

        return $user->clinicCan($permission, $roleAllows);
    }

    public function updateAvatar(User $user, User $target): bool
    {
        return $user->id === $target->id;
    }

    public function deleteAvatar(User $user, User $target): bool
    {
        return $user->id === $target->id;
    }
}
