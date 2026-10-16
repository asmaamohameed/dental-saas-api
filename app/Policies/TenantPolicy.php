<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function updateWorkingHours(User $user, Tenant $tenant): bool
    {
        return $user->isOwner()
            && (string) $user->tenant_id === (string) $tenant->id;
    }
}
