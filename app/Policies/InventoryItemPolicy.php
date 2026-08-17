<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\User;

class InventoryItemPolicy
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
        return in_array($user->role, [
            UserRole::DOCTOR,
            UserRole::RECEPTIONIST,
        ], true);
    }

    public function view(User $user, InventoryItem $item): bool
    {
        return $user->tenant_id === $item->tenant_id
            && in_array($user->role, [
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ], true);
    }

    public function create(User $user): bool
    {
        // Only the owner may create items — granted via before().
        return false;
    }

    public function update(User $user, InventoryItem $item): bool
    {
        // Only the owner may update items — granted via before().
        return false;
    }

    public function delete(User $user, InventoryItem $item): bool
    {
        // Only the owner may deactivate items — granted via before().
        return false;
    }

    public function restore(User $user, InventoryItem $item): bool
    {
        // Only the owner may restore items — granted via before().
        return false;
    }
}
