<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::OWNER;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::OWNER;
    }

    public function update(User $user, User $target): bool
    {
        return $user->role === UserRole::OWNER
            && $user->tenant_id === $target->tenant_id
            && $target->role !== UserRole::OWNER;
    }

    public function toggleActive(User $user, User $target): bool
    {
        return $this->update($user, $target);
    }
}
