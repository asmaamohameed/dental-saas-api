<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\User;

class InventoryItemPolicy
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
        return $user->clinicCan('inventory:view', $user->hasAnyClinicRole([
            UserRole::DOCTOR,
            UserRole::ASSISTANT,
            UserRole::RECEPTIONIST,
        ]));
    }

    public function view(User $user, InventoryItem $item): bool
    {
        return $user->tenant_id === $item->tenant_id
            && $user->clinicCan('inventory:view', $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]));
    }

    public function create(User $user): bool
    {
        return $user->clinicCan('inventory:create', false);
    }

    public function update(User $user, InventoryItem $item): bool
    {
        return $user->tenant_id === $item->tenant_id
            && $user->clinicCan('inventory:edit', false);
    }

    public function delete(User $user, InventoryItem $item): bool
    {
        return $user->tenant_id === $item->tenant_id
            && $user->clinicCan('inventory:delete', false);
    }

    public function restore(User $user, InventoryItem $item): bool
    {
        return $user->tenant_id === $item->tenant_id
            && $user->clinicCan('inventory:restore', false);
    }

    public function viewInactive(User $user): bool
    {
        // Only the owner may list inactive or all items — granted via before().
        return false;
    }
}
