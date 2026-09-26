<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\InventoryTransaction;
use App\Models\User;

class InventoryTransactionPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($ability === 'delete') {
            return null;
        }

        if ($user->isOwner()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user, InventoryItem $item): bool
    {
        return $user->tenant_id === $item->tenant_id
            && $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]);
    }

    public function view(User $user, InventoryTransaction $transaction): bool
    {
        return $user->tenant_id === $transaction->tenant_id
            && $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::ASSISTANT,
                UserRole::RECEPTIONIST,
            ]);
    }

    public function create(User $user, InventoryItem $item): bool
    {
        return $user->tenant_id === $item->tenant_id
            && $user->hasAnyClinicRole([
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ]);
    }
}
