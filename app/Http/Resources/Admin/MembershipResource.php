<?php

namespace App\Http\Resources\Admin;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ClinicMember
 */
class MembershipResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $clinic = $this->clinic;
        $roles = $this->roles->pluck('name')->values();
        $clinicStatus = $clinic?->status instanceof TenantStatus ? $clinic->status->value : $clinic?->status;
        $accountRole = $user?->role instanceof UserRole ? $user->role->value : $user?->role;

        return [
            'id' => $this->id,
            'is_active' => (bool) $this->is_active,
            'roles' => $roles,
            'effective_roles' => $roles,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => (bool) $user->is_active,
                'account_role' => $accountRole,
                'primary_clinic_id' => $user->tenant_id,
            ] : null,
            'clinic' => $clinic ? [
                'id' => $clinic->id,
                'name' => $clinic->name,
                'subdomain' => $clinic->subdomain,
                'status' => $clinicStatus,
            ] : null,
        ];
    }
}
