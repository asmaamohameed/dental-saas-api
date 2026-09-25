<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class UserService
{
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = User::query()->where('role', '!=', UserRole::OWNER);

        if (! empty($filters['role'])) {
            $query->where('role', $filters['role']);
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
        $query = User::query()->where('is_active', true);

        if ($day === null) {
            return $query
                ->where('role', UserRole::DOCTOR)
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $staff = $query
            ->whereIn('role', [UserRole::DOCTOR, UserRole::ASSISTANT])
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
        if (! empty($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
        }
        unset($data['password']);

        $role = isset($data['role']) ? UserRole::from($data['role']) : $user->role;
        $this->applyWorkingDaysForRole($data, $role, isUpdate: true);

        $user->update($data);

        return $user->fresh();
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
        $user->update(['is_active' => ! $user->is_active]);

        return $user->fresh();
    }
}
