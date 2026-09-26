<?php

namespace App\Models\Concerns;

use App\Enums\UserRole;
use App\Models\Clinic;
use App\Models\ClinicMember;
use Illuminate\Database\Eloquent\Relations\HasMany;

trait HasClinicRoles
{
    public function clinicMembers(): HasMany
    {
        return $this->hasMany(ClinicMember::class);
    }

    public function membershipIn(Clinic $clinic): ?ClinicMember
    {
        return $this->membershipForClinicId((string) $clinic->getKey());
    }

    public function hasRoleIn(Clinic $clinic, string $role): bool
    {
        return (bool) $this->membershipIn($clinic)?->hasRole($role);
    }

    public function currentClinicMembership(): ?ClinicMember
    {
        if (! $this->exists || ! $this->tenant_id) {
            return null;
        }

        return $this->membershipForClinicId((string) $this->tenant_id);
    }

    public function hasClinicRole(UserRole|string $role): bool
    {
        $name = $role instanceof UserRole ? $role->value : $role;
        $membership = $this->currentClinicMembership();

        if ($membership && $membership->roles->isNotEmpty()) {
            return $membership->hasRole($name);
        }

        return $this->legacyRoleName() === $name;
    }

    /**
     * @param  list<UserRole|string>  $roles
     */
    public function hasAnyClinicRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasClinicRole($role)) {
                return true;
            }
        }

        return false;
    }

    public function isOwner(): bool
    {
        return $this->hasClinicRole(UserRole::OWNER);
    }

    public function isDoctor(): bool
    {
        return $this->hasClinicRole(UserRole::DOCTOR);
    }

    public function isAssistant(): bool
    {
        return $this->hasClinicRole(UserRole::ASSISTANT);
    }

    public function isReceptionist(): bool
    {
        return $this->hasClinicRole(UserRole::RECEPTIONIST);
    }

    /**
     * @return list<string>
     */
    public function currentClinicRoleNames(): array
    {
        $membership = $this->currentClinicMembership();

        if ($membership && $membership->roles->isNotEmpty()) {
            return $membership->roles->pluck('name')->values()->all();
        }

        $legacy = $this->legacyRoleName();

        return $legacy ? [$legacy] : [];
    }

    public function membershipForClinicId(string $clinicId): ?ClinicMember
    {
        if ($this->relationLoaded('clinicMembers')) {
            $member = $this->clinicMembers->firstWhere('clinic_id', $clinicId);

            if ($member && ! $member->relationLoaded('roles')) {
                $member->load('roles');
            }

            return $member;
        }

        return $this->clinicMembers()->with('roles')->where('clinic_id', $clinicId)->first();
    }

    public static function constrainUsersWithClinicRole(object $query, string $clinicId, string $role): void
    {
        $query->whereIn('id', function ($sub) use ($clinicId, $role): void {
            $sub->select('clinic_members.user_id')
                ->from('clinic_members')
                ->join('clinic_member_role', 'clinic_member_role.clinic_member_id', '=', 'clinic_members.id')
                ->join('roles', 'roles.id', '=', 'clinic_member_role.role_id')
                ->where('clinic_members.clinic_id', $clinicId)
                ->where('roles.name', $role);
        });
    }

    private function legacyRoleName(): ?string
    {
        $role = $this->getAttribute('role');

        if ($role instanceof UserRole) {
            return $role->value;
        }

        return is_string($role) && $role !== '' ? $role : null;
    }
}
