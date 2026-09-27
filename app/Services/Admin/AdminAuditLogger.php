<?php

namespace App\Services\Admin;

use App\Models\AdminAuditLog;
use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AdminAuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        AdminUser $admin,
        string $action,
        string $targetType,
        ?string $targetId,
        ?string $tenantId,
        ?array $before,
        ?array $after,
        Request $request,
    ): AdminAuditLog {
        return AdminAuditLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'tenant_id' => $tenantId,
            'before_values' => $this->redact($before),
            'after_values' => $this->redact($after),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }

    public function logModel(
        AdminUser $admin,
        string $action,
        Model $target,
        string $targetType,
        ?string $tenantId,
        ?array $before,
        ?array $after,
        Request $request,
    ): AdminAuditLog {
        return $this->log(
            $admin,
            $action,
            $targetType,
            (string) $target->getKey(),
            $tenantId,
            $before,
            $after,
            $request,
        );
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $hidden = [
            'password',
            'password_hash',
            'remember_token',
            'token',
            'access_token',
            'secret',
            'authorization',
        ];

        $clean = [];

        foreach ($values as $key => $value) {
            if (in_array(strtolower((string) $key), $hidden, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }
}
