<?php

namespace App\Http\Resources\Admin;

use App\Enums\UserRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\User
 */
class PlatformUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $accountRole = $this->role instanceof UserRole ? $this->role->value : $this->role;
        $memberships = $this->clinicMembers->each(function ($membership): void {
            $membership->setRelation('user', $this->resource);
        });

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,
            'account_role' => $accountRole,
            'primary_clinic_id' => $this->tenant_id,
            'locale' => $this->locale,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'last_activity_at' => $this->resource->getAttribute('last_activity_at'),
            'issues' => $this->issues($accountRole),
            'memberships' => MembershipResource::collection($memberships),
        ];
    }

    /**
     * @return list<array{code: string, severity: string, message: string}>
     */
    private function issues(?string $accountRole): array
    {
        $issues = [];
        $memberships = $this->clinicMembers;

        if ($memberships->isEmpty()) {
            $issues[] = [
                'code' => 'no_memberships',
                'severity' => 'warning',
                'message' => 'This user has no clinic memberships.',
            ];
        }

        if ($memberships->count() > 1) {
            $issues[] = [
                'code' => 'multiple_clinics',
                'severity' => 'info',
                'message' => 'This user belongs to more than one clinic. Each role applies only inside its clinic.',
            ];
        }

        $primary = $memberships->firstWhere('clinic_id', $this->tenant_id);

        if ($primary && $primary->roles->isNotEmpty() && $accountRole && ! $primary->roles->contains(fn ($role) => $role->name === $accountRole)) {
            $issues[] = [
                'code' => 'role_drift',
                'severity' => 'warning',
                'message' => 'The stored account role does not match this user\'s role in their primary clinic.',
            ];
        }

        return $issues;
    }
}
