<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Roles\ClinicMembershipSync;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = User::query()
            ->where('role', '!=', UserRole::OWNER->value)
            ->whereDoesntHave('clinicMembers', function ($members) {
                $members->whereColumn('clinic_members.clinic_id', 'users.tenant_id')
                    ->whereHas('roles', fn ($roles) => $roles->where('name', UserRole::OWNER->value));
            });

        if (! empty($filters['role'])) {
            $role = $filters['role'];
            $query->where(function ($members) use ($role) {
                $members->where('role', $role)
                    ->orWhereHas('clinicMembers.roles', fn ($roles) => $roles->where('name', $role));
            });
        }

        if (! empty($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        return $query->latest()->paginate($perPage);
    }

    public function listDoctors(): Collection
    {
        $clinicId = app(CurrentTenant::class)->id();

        if (! $clinicId) {
            return collect();
        }

        return User::query()
            ->withoutGlobalScope('tenant')
            ->where('is_active', true)
            ->where(function ($query) use ($clinicId) {
                User::constrainUsersWithClinicRole($query, (string) $clinicId, UserRole::DOCTOR->value);
            })
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function create(array $data): User
    {
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password']);

        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $roleChanged = array_key_exists('role', $data);

        if ($user->isOwner()) {
            unset($data['role']);
            $roleChanged = false;
        }

        if (! empty($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
        }
        unset($data['password']);

        $user->update($data);

        if ($roleChanged) {
            ClinicMembershipSync::replaceLegacyRole($user->fresh());
        }

        return $user->fresh();
    }

    public function toggleActive(User $user): User
    {
        $user->update(['is_active' => ! $user->is_active]);

        return $user->fresh();
    }
}
