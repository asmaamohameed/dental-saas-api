<?php

namespace App\Services\Admin;

use App\Models\AdminUser;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminUserService
{
    public function __construct(
        private readonly AdminAuditLogger $audit,
        private readonly AdminMembershipService $memberships,
        private readonly CurrentTenant $currentTenant,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $this->currentTenant->clear();

        $query = $this->baseQuery();

        if (! empty($filters['search'])) {
            $search = '%'.str_replace(['%', '_'], '', (string) $filters['search']).'%';
            $query->where(function ($inner) use ($search): void {
                $inner->where('name', 'like', $search)
                    ->orWhere('email', 'like', $search);
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (! empty($filters['clinic_id'])) {
            $query->whereExists(function ($members) use ($filters): void {
                $members->selectRaw('1')
                    ->from('clinic_members')
                    ->whereColumn('clinic_members.user_id', 'users.id')
                    ->where('clinic_members.clinic_id', $filters['clinic_id']);
            });
        }

        return $query->latest('users.created_at')->paginate((int) ($filters['per_page'] ?? 15));
    }

    public function show(string $userId): User
    {
        $this->currentTenant->clear();

        $user = $this->baseQuery()->whereKey($userId)->first();

        if (! $user) {
            abort(404, 'Resource not found.');
        }

        return $user;
    }

    public function activate(string $userId, AdminUser $admin, Request $request): User
    {
        return $this->setActive($userId, true, $admin, $request);
    }

    public function deactivate(string $userId, AdminUser $admin, Request $request): User
    {
        return $this->setActive($userId, false, $admin, $request);
    }

    public function revokeSessions(string $userId, AdminUser $admin, Request $request): User
    {
        return DB::transaction(function () use ($userId, $admin, $request) {
            $this->currentTenant->clear();
            $user = $this->lockUser($userId);
            $deleted = $user->tokens()->delete();

            $this->audit->logModel(
                $admin,
                'user.sessions_revoked',
                $user,
                'user',
                $user->tenant_id ? (string) $user->tenant_id : null,
                null,
                ['revoked_sessions' => $deleted],
                $request,
            );

            return $this->show($user->id);
        });
    }

    private function setActive(string $userId, bool $active, AdminUser $admin, Request $request): User
    {
        return DB::transaction(function () use ($userId, $active, $admin, $request) {
            $this->currentTenant->clear();
            $user = $this->lockUser($userId);

            if ($user->is_active === $active) {
                return $this->show($user->id);
            }

            if (! $active) {
                $this->memberships->assertUserCanBeDeactivated($user);
                $user->tokens()->delete();
            }

            $before = ['is_active' => $user->is_active];
            $user->forceFill(['is_active' => $active])->save();

            $this->audit->logModel(
                $admin,
                $active ? 'user.activated' : 'user.deactivated',
                $user,
                'user',
                $user->tenant_id ? (string) $user->tenant_id : null,
                $before,
                ['is_active' => $active],
                $request,
            );

            return $this->show($user->id);
        });
    }

    private function lockUser(string $userId): User
    {
        $user = User::query()->withoutGlobalScope('tenant')->whereKey($userId)->lockForUpdate()->first();

        if (! $user) {
            abort(404, 'Resource not found.');
        }

        return $user;
    }

    private function baseQuery()
    {
        return User::query()
            ->withoutGlobalScope('tenant')
            ->select(['id', 'name', 'email', 'phone', 'role', 'is_active', 'tenant_id', 'locale', 'created_at', 'updated_at'])
            ->withMax('tokens as last_activity_at', 'last_used_at')
            ->with([
                'clinicMembers.roles',
                'clinicMembers.clinic:id,name,subdomain,status',
            ]);
    }
}
