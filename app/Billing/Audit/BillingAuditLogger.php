<?php

namespace App\Billing\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes AuditLog rows. Invoice items stay off the Auditable trait.
 */
final class BillingAuditLogger
{
    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    public function record(User $actor, Model $auditable, AuditAction $action, array $oldValues, array $newValues): AuditLog
    {
        return AuditLog::query()->create([
            'tenant_id' => $auditable->getAttribute('tenant_id'),
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
