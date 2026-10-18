<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->clinicCan('auditLog:view', $user->isOwner());
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $user->tenant_id === $auditLog->tenant_id
            && $user->clinicCan('auditLog:view', $user->isOwner());
    }
}
