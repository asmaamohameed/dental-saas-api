<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\AdminUser;
use App\Models\Clinic;
use App\Models\ClinicMember;
use App\Models\Role;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdminMembershipService
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly CurrentTenant $currentTenant,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $this->currentTenant->clear();

        $query = ClinicMember::query()->with([
            'roles',
            'clinic:id,name,subdomain,status',
            'user' => fn ($users) => $users->withoutGlobalScope('tenant')
                ->select(['id', 'name', 'email', 'is_active', 'tenant_id', 'role', 'created_at']),
        ]);

        if (! empty($filters['clinic_id'])) {
            $query->where('clinic_id', $filters['clinic_id']);
        }

        if (! empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['role'])) {
            $query->whereHas('roles', fn ($roles) => $roles->where('name', $filters['role']));
        }

        if (! empty($filters['search'])) {
            $search = $this->like((string) $filters['search']);
            $query->where(function ($inner) use ($search): void {
                $inner->whereExists(function ($users) use ($search): void {
                    $users->selectRaw('1')
                        ->from('users')
                        ->whereColumn('users.id', 'clinic_members.user_id')
                        ->where(function ($match) use ($search): void {
                            $match->where('users.name', 'like', $search)
                                ->orWhere('users.email', 'like', $search);
                        });
                })->orWhereExists(function ($clinics) use ($search): void {
                    $clinics->selectRaw('1')
                        ->from('tenants')
                        ->whereColumn('tenants.id', 'clinic_members.clinic_id')
                        ->where(function ($match) use ($search): void {
                            $match->where('tenants.name', 'like', $search)
                                ->orWhere('tenants.subdomain', 'like', $search);
                        });
                });
            });
        }

        return $query->latest('clinic_members.created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    /**
     * Create a clinic user or grant an existing user one clinic role.
     * Existing roles on that membership are kept.
     *
     * @param  array{role: string, user_id?: string, name?: string, email?: string, password?: string, phone?: string|null}  $data
     */
    public function addToClinic(Clinic $clinic, array $data, AdminUser $admin, Request $request): ClinicMember
    {
        if (! empty($data['user_id'])) {
            return $this->grantRole((string) $clinic->id, (string) $data['user_id'], $data['role'], $admin, $request);
        }

        return $this->createUserWithRole($clinic, $data, $admin, $request);
    }

    /**
     * @param  array{user_id: string, clinic_id: string, role: string}  $data
     */
    public function create(array $data, AdminUser $admin, Request $request): ClinicMember
    {
        return DB::transaction(function () use ($data, $admin, $request) {
            $this->currentTenant->clear();

            $existing = ClinicMember::query()
                ->where('clinic_id', $data['clinic_id'])
                ->where('user_id', $data['user_id'])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->is_active) {
                    abort(422, 'This user is already a member of that clinic.');
                }

                abort(422, 'This user already has an inactive membership in that clinic. Restore it instead of creating another.');
            }

            $role = $this->role($data['role']);
            $member = ClinicMember::query()->create([
                'clinic_id' => $data['clinic_id'],
                'user_id' => $data['user_id'],
                'is_active' => true,
            ]);
            $member->roles()->sync([$role->id]);
            $this->syncPrimaryRole($member, $data['role']);

            $this->audit->logModel(
                $admin,
                'membership.created',
                $member,
                'membership',
                (string) $member->clinic_id,
                null,
                [
                    'user_id' => $member->user_id,
                    'clinic_id' => $member->clinic_id,
                    'role' => $data['role'],
                    'is_active' => true,
                ],
                $request,
            );

            return $this->fresh($member);
        });
    }

    public function changeRole(ClinicMember $member, string $roleName, AdminUser $admin, Request $request): ClinicMember
    {
        return DB::transaction(function () use ($member, $roleName, $admin, $request) {
            $this->currentTenant->clear();
            $locked = $this->lockMember($member->id);
            $before = $locked->roles->pluck('name')->values()->all();
            $willRemainOwner = $roleName === UserRole::OWNER->value;

            if ($locked->is_active && $locked->hasRole(UserRole::OWNER->value) && ! $willRemainOwner) {
                $this->assertKeepsAnActiveOwner((string) $locked->clinic_id, (string) $locked->id);
            }

            $role = $this->role($roleName);
            $locked->roles()->sync([$role->id]);
            $this->syncPrimaryRole($locked, $roleName);

            $this->audit->logModel(
                $admin,
                'membership.role_changed',
                $locked,
                'membership',
                (string) $locked->clinic_id,
                ['roles' => $before],
                ['roles' => [$roleName]],
                $request,
            );

            return $this->fresh($locked);
        });
    }

    public function revoke(ClinicMember $member, AdminUser $admin, Request $request): ClinicMember
    {
        return $this->setActive($member, false, 'membership.revoked', $admin, $request);
    }

    public function restore(ClinicMember $member, AdminUser $admin, Request $request): ClinicMember
    {
        return $this->setActive($member, true, 'membership.restored', $admin, $request);
    }

    private function setActive(ClinicMember $member, bool $active, string $action, AdminUser $admin, Request $request): ClinicMember
    {
        return DB::transaction(function () use ($member, $active, $action, $admin, $request) {
            $this->currentTenant->clear();
            $locked = $this->lockMember($member->id);

            if ($locked->is_active === $active) {
                return $this->fresh($locked);
            }

            if (! $active && $locked->hasRole(UserRole::OWNER->value)) {
                $this->assertKeepsAnActiveOwner((string) $locked->clinic_id, (string) $locked->id);
            }

            $before = ['is_active' => $locked->is_active];
            $locked->update(['is_active' => $active]);

            $this->audit->logModel(
                $admin,
                $action,
                $locked,
                'membership',
                (string) $locked->clinic_id,
                $before,
                ['is_active' => $active],
                $request,
            );

            return $this->fresh($locked);
        });
    }

    public function grantRole(string $clinicId, string $userId, string $roleName, AdminUser $admin, Request $request): ClinicMember
    {
        return DB::transaction(function () use ($clinicId, $userId, $roleName, $admin, $request) {
            $this->currentTenant->clear();

            $user = User::query()->withoutGlobalScope('tenant')->whereKey($userId)->lockForUpdate()->first();

            if (! $user) {
                abort(404, 'Resource not found.');
            }

            $role = $this->role($roleName);
            $member = ClinicMember::query()
                ->where('clinic_id', $clinicId)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if (! $member) {
                $member = ClinicMember::query()->create([
                    'clinic_id' => $clinicId,
                    'user_id' => $userId,
                    'is_active' => true,
                ]);
                $member->roles()->sync([$role->id]);
                $this->syncPrimaryRole($member, $roleName);
                $this->audit->logModel($admin, 'membership.created', $member, 'membership', $clinicId, null, [
                    'user_id' => $userId,
                    'clinic_id' => $clinicId,
                    'role' => $roleName,
                    'is_active' => true,
                ], $request);

                return $this->fresh($member);
            }

            $member->load('roles');
            $alreadyHasRole = $member->hasRole($roleName);

            if ($alreadyHasRole && $member->is_active) {
                abort(422, 'This user already has that role in this clinic.');
            }

            $before = [
                'is_active' => $member->is_active,
                'roles' => $member->roles->pluck('name')->values()->all(),
            ];

            if (! $member->is_active) {
                $member->update(['is_active' => true]);
            }

            if (! $alreadyHasRole) {
                $member->roles()->syncWithoutDetaching([$role->id]);
            }

            $member->unsetRelation('roles');
            $this->audit->logModel($admin, 'membership.role_granted', $member, 'membership', $clinicId, $before, [
                'is_active' => true,
                'roles' => $member->roles()->pluck('name')->values()->all(),
            ], $request);

            return $this->fresh($member);
        });
    }

    /**
     * @param  array{role: string, name?: string, email?: string, password?: string, phone?: string|null}  $data
     */
    private function createUserWithRole(Clinic $clinic, array $data, AdminUser $admin, Request $request): ClinicMember
    {
        return DB::transaction(function () use ($clinic, $data, $admin, $request) {
            $email = strtolower((string) $data['email']);
            $taken = User::query()
                ->withoutGlobalScope('tenant')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->exists();

            if ($taken) {
                abort(422, 'A user with this email already exists. Choose that user and add the role instead.');
            }

            $this->currentTenant->set($clinic->id);

            try {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $email,
                    'phone' => $data['phone'] ?? null,
                    'password_hash' => Hash::make((string) $data['password']),
                    'role' => $data['role'],
                    'locale' => $clinic->locale,
                    'is_active' => true,
                ]);
            } finally {
                $this->currentTenant->clear();
            }

            $member = ClinicMember::query()
                ->where('clinic_id', $clinic->id)
                ->where('user_id', $user->id)
                ->first();

            if (! $member) {
                $role = $this->role($data['role']);
                $member = ClinicMember::query()->create([
                    'clinic_id' => $clinic->id,
                    'user_id' => $user->id,
                    'is_active' => true,
                ]);
                $member->roles()->sync([$role->id]);
            }

            $this->audit->logModel($admin, 'user.created', $user, 'user', (string) $clinic->id, null, [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'clinic_id' => $clinic->id,
                'role' => $data['role'],
            ], $request);

            return $this->fresh($member);
        });
    }

    public function assertUserCanBeDeactivated(User $user): void
    {
        $memberships = ClinicMember::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', UserRole::OWNER->value))
            ->lockForUpdate()
            ->get();

        foreach ($memberships as $membership) {
            $this->assertKeepsAnActiveOwner((string) $membership->clinic_id, (string) $membership->id);
        }
    }

    private function assertKeepsAnActiveOwner(string $clinicId, string $memberIdBeingRemoved): void
    {
        $owners = ClinicMember::query()
            ->where('clinic_id', $clinicId)
            ->where('is_active', true)
            ->whereHas('roles', fn ($roles) => $roles->where('name', UserRole::OWNER->value))
            ->lockForUpdate()
            ->get(['id', 'user_id']);

        $activeUserIds = User::query()
            ->withoutGlobalScope('tenant')
            ->whereIn('id', $owners->pluck('user_id'))
            ->where('is_active', true)
            ->lockForUpdate()
            ->pluck('id');

        $remaining = $owners->filter(function (ClinicMember $owner) use ($activeUserIds, $memberIdBeingRemoved) {
            return (string) $owner->id !== $memberIdBeingRemoved
                && $activeUserIds->contains($owner->user_id);
        });

        if ($remaining->isEmpty()) {
            abort(422, 'This clinic must keep at least one active owner. Assign another owner first.');
        }
    }

    private function syncPrimaryRole(ClinicMember $member, string $roleName): void
    {
        $user = User::query()
            ->withoutGlobalScope('tenant')
            ->whereKey($member->user_id)
            ->lockForUpdate()
            ->first();

        if (! $user || (string) $user->tenant_id !== (string) $member->clinic_id) {
            return;
        }

        $user->forceFill(['role' => $roleName])->save();
    }

    private function role(string $name): Role
    {
        $role = Role::query()->where('name', $name)->first();

        if (! $role || UserRole::tryFrom($name) === null) {
            abort(422, 'That clinic role is not available.');
        }

        return $role;
    }

    private function lockMember(string $id): ClinicMember
    {
        $member = ClinicMember::query()->whereKey($id)->lockForUpdate()->first();

        if (! $member) {
            abort(404, 'Resource not found.');
        }

        $member->load('roles');

        return $member;
    }

    private function fresh(ClinicMember $member): ClinicMember
    {
        return $member->fresh()->load([
            'roles',
            'clinic:id,name,subdomain,status',
            'user' => fn ($users) => $users->withoutGlobalScope('tenant')
                ->select(['id', 'name', 'email', 'is_active', 'tenant_id', 'role', 'created_at']),
        ]);
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['%', '_'], '', $value).'%';
    }
}
