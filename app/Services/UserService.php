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

    public function listDoctors(): Collection
    {
        return User::query()
            ->where('role', UserRole::DOCTOR)
            ->where('is_active', true)
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
        if (! empty($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
        }
        unset($data['password']);

        $user->update($data);

        return $user->fresh();
    }

    public function toggleActive(User $user): User
    {
        $user->update(['is_active' => ! $user->is_active]);

        return $user->fresh();
    }
}
