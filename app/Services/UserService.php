<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Roles\ClinicMembershipSync;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

    public function listDoctors(?string $day = null): Collection
    {
        $clinicId = app(CurrentTenant::class)->id();

        if (! $clinicId) {
            return collect();
        }

        $query = User::query()
            ->withoutGlobalScope('tenant')
            ->where('is_active', true);

        if ($day === null) {
            return $query
                ->where(function ($doctors) use ($clinicId) {
                    User::constrainUsersWithClinicRole($doctors, (string) $clinicId, UserRole::DOCTOR->value);
                })
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $staff = $query
            ->where(function ($staff) use ($clinicId) {
                $staff->where(function ($doctors) use ($clinicId) {
                    User::constrainUsersWithClinicRole($doctors, (string) $clinicId, UserRole::DOCTOR->value);
                })->orWhere(function ($assistants) use ($clinicId) {
                    User::constrainUsersWithClinicRole($assistants, (string) $clinicId, UserRole::ASSISTANT->value);
                });
            })
            ->orderBy('name')
            ->get(['id', 'name', 'working_days']);

        return $staff
            ->sortBy([
                fn (User $user) => in_array($day, $user->working_days ?? [], true) ? 0 : 1,
                fn (User $user) => $user->name,
            ])
            ->values();
    }

    public function create(array $data): User
    {
        $data['password_hash'] = Hash::make($data['password']);
        unset($data['password']);

        $role = UserRole::from($data['role']);
        $this->applyWorkingDaysForRole($data, $role, isUpdate: false);

        return User::create($data);
    }

    public function update(User $user, array $data): User
    {
        $previousRole = $user->role?->value;
        $roleChanged = array_key_exists('role', $data);

        if ($user->isOwner()) {
            unset($data['role']);
            $roleChanged = false;
        }

        if (! empty($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
        }
        unset($data['password']);

        $role = isset($data['role']) ? UserRole::from($data['role']) : $user->role;
        $this->applyWorkingDaysForRole($data, $role, isUpdate: true);

        $user->update($data);

        if ($roleChanged) {
            ClinicMembershipSync::replaceLegacyRole($user->fresh() ?? $user);
        }

        $fresh = $user->fresh() ?? $user;

        if ($roleChanged && $previousRole !== $fresh->role?->value) {
            $this->writeStaffAudit(
                $fresh,
                AuditAction::STAFF_ROLE_CHANGED,
                ['role' => $previousRole],
                ['role' => $fresh->role?->value],
            );
        }

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyWorkingDaysForRole(array &$data, UserRole $role, bool $isUpdate): void
    {
        $scheduleRole = in_array($role, [UserRole::DOCTOR, UserRole::ASSISTANT], true);

        if (! $scheduleRole) {
            $data['working_days'] = null;

            return;
        }

        if ($isUpdate && ! array_key_exists('working_days', $data)) {
            unset($data['working_days']);
        }
    }

    public function toggleActive(User $user): User
    {
        $actor = Auth::user();

        if ($actor instanceof User && $actor->id === $user->id && $user->isOwner() && $user->is_active) {
            throw ValidationException::withMessages([
                'is_active' => 'The clinic owner cannot deactivate their own account.',
            ]);
        }

        $wasActive = $user->is_active;
        $user->update(['is_active' => ! $wasActive]);
        $fresh = $user->fresh() ?? $user;

        $this->writeStaffAudit(
            $fresh,
            AuditAction::STAFF_ACTIVE_STATUS_CHANGED,
            ['is_active' => $wasActive],
            ['is_active' => $fresh->is_active],
        );

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     */
    private function writeStaffAudit(User $staff, AuditAction $action, array $oldValues, array $newValues): void
    {
        $actor = Auth::user();

        if (! $actor instanceof User) {
            return;
        }

        AuditLog::query()->create([
            'tenant_id' => $staff->tenant_id,
            'user_id' => $actor->id,
            'action' => $action,
            'auditable_type' => $staff->getMorphClass(),
            'auditable_id' => $staff->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
