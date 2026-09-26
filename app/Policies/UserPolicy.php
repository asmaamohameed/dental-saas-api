<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isOwner();
    }

    public function create(User $user): bool
    {
        return $user->isOwner();
    }

    public function update(User $user, User $target): bool
    {
        if ($user->tenant_id === null || $user->tenant_id !== $target->tenant_id) {
            return false;
        }

        $actor = $user->currentClinicMembership();
        $subject = $target->currentClinicMembership();

        if ($actor && $subject) {
            return $actor->canManage($subject);
        }

        return $user->isOwner() && ! $target->isOwner();
    }

    public function toggleActive(User $user, User $target): bool
    {
        return $this->update($user, $target);
    }
}
