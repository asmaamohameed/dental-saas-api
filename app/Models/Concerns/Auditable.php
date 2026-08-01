<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            $model->logAuditAction('created', null, $model->getAttributes());
        });

        static::updated(function (Model $model) {
            $oldValues = Arr::only($model->getOriginal(), array_keys($model->getChanges()));
            $newValues = $model->getChanges();

            // Remove timestamps if we don't care about them in audit logs
            Arr::forget($oldValues, ['updated_at']);
            Arr::forget($newValues, ['updated_at']);

            if (! empty($newValues)) {
                $model->logAuditAction('updated', $oldValues, $newValues);
            }
        });

        static::deleted(function (Model $model) {
            $model->logAuditAction('deleted', $model->getAttributes(), null);
        });
    }

    protected function logAuditAction(string $action, ?array $oldValues = null, ?array $newValues = null): void
    {
        // Don't log if running from console/seeders without a user, unless we want to track system changes
        if (! auth()->check()) {
            return;
        }

        AuditLog::create([
            'tenant_id' => $this->tenant_id ?? (auth()->user()->tenant_id ?? null),
            'user_id' => auth()->id(),
            'action' => $action,
            'auditable_type' => get_class($this),
            'auditable_id' => $this->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
