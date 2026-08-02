<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;


trait Auditable
{
    /**
     * Attributes that should never be written to the audit log
     * (secrets, tokens, or anything irrelevant to auditing).
     */
    protected static array $auditExcept = ['password', 'remember_token'];

    protected static function bootAuditable(): void
    {
        static::created(static function (Model $model): void {
            $model->recordAudit('created', null, $model->getAttributes());
        });

        static::updated(static function (Model $model): void {
            $changes = $model->getChanges();

            // Timestamps (and any excluded attrs) shouldn't trigger/pollute the log.
            Arr::forget($changes, array_merge(['updated_at'], static::$auditExcept));

            if ($changes === []) {
                return;
            }

            $original = Arr::only($model->getOriginal(), array_keys($changes));

            $model->recordAudit('updated', $original, $changes);
        });

        static::deleted(static function (Model $model): void {
            $model->recordAudit('deleted', $model->getAttributes(), null);
        });
    }

    protected function recordAudit(string $action, ?array $oldValues, ?array $newValues): void
    {
        $user = Auth::user();

        // No authenticated user => console/system action. Skip explicitly
        // rather than silently guessing at a "system" identity.
        if (! $user) {
            return;
        }

        $oldValues = $oldValues ? Arr::except($oldValues, static::$auditExcept) : $oldValues;
        $newValues = $newValues ? Arr::except($newValues, static::$auditExcept) : $newValues;

        AuditLog::query()->create([
            'tenant_id' => $this->tenant_id ?? $user->tenant_id ?? null,
            'user_id' => $user->getAuthIdentifier(),
            'action' => $action,
            'auditable_type' => $this->getMorphClass(),
            'auditable_id' => $this->getKey(),
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
