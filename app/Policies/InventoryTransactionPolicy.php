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
        if ($user->role?->isOwner()) {
            return true;
        }

        return null;
    }

    public function viewAny(User $user, InventoryItem $item): bool
    {
        return $user->tenant_id === $item->tenant_id
            && in_array($user->role, [
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ], true);
    }

    public function view(User $user, InventoryTransaction $transaction): bool
    {
        return $user->tenant_id === $transaction->tenant_id
            && in_array($user->role, [
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ], true);
    }

    public function create(User $user, InventoryItem $item): bool
    {
        return $user->tenant_id === $item->tenant_id
            && in_array($user->role, [
                UserRole::DOCTOR,
                UserRole::RECEPTIONIST,
            ], true);
    }

    public function delete(User $user, InventoryTransaction $transaction): bool
    {
        // Transactions are append-only — never delete.
        return false;
    }
}
