<?php

namespace App\Http\Resources\Admin;

use App\Enums\TenantStatus;
use App\Models\Clinic;
use App\Models\ClinicMember;
use App\Support\Admin\ClinicIntegrity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Clinic
 */
class ClinicResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->status->value;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'subdomain' => $this->subdomain,
            'locale' => $this->locale,
            'status' => $status,
            'is_suspended' => $status !== TenantStatus::ACTIVE->value,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'last_activity_at' => $this->resource->getAttribute('last_activity_at'),
            'counts' => [
                'members' => (int) ($this->members_count ?? 0),
                'active_members' => (int) ($this->active_members_count ?? 0),
                'active_owners' => (int) ($this->active_owner_count ?? 0),
                'doctors' => (int) ($this->doctor_count ?? 0),
                'inactive_memberships' => (int) ($this->inactive_membership_count ?? 0),
            ],
            'issues' => ClinicIntegrity::issues($this->resource),
            'owners' => $this->when($this->relationLoaded('listedOwners'), function (): array {
                return $this->listedOwners
                    ->map(function (ClinicMember $member): array {
                        $user = $member->user;

                        return [
                            'membership_id' => $member->id,
                            'membership_active' => (bool) $member->is_active,
                            'roles' => $member->roles->pluck('name')->values()->all(),
                            'user' => $user ? [
                                'id' => $user->id,
                                'name' => $user->name,
                                'email' => $user->email,
                                'is_active' => (bool) $user->is_active,
                            ] : null,
                        ];
                    })
                    ->values()
                    ->all();
            }),
        ];
    }
}
