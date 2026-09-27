<?php

namespace App\Http\Resources\Admin;

use App\Services\Admin\AdminAuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\AdminAuditLog
 */
class AdminAuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $logger = app(AdminAuditLogger::class);

        return [
            'id' => $this->id,
            'action' => $this->action,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'tenant_id' => $this->tenant_id,
            'before' => $logger->redact($this->before_values),
            'after' => $logger->redact($this->after_values),
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at,
            'admin' => $this->admin ? [
                'id' => $this->admin->id,
                'name' => $this->admin->name,
                'email' => $this->admin->email,
            ] : null,
        ];
    }
}
