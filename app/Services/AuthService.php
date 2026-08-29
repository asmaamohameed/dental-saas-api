<?php

namespace App\Services;

use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthService
{
    public function loginUser(array $credentials, string $device = 'api_token'): array
    {
        $user = User::withoutGlobalScope('tenant')
            ->with('tenant')
            ->where('email', $credentials['email'])
            ->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        return [
            'user' => $user,
            'token' => $user->createToken($device)->plainTextToken,
        ];
    }

    public function loginAdmin(array $credentials, string $device = 'admin_api_token'): string
    {
        $admin = AdminUser::where('email', $credentials['email'])->first();

        if (! $admin || ! Hash::check($credentials['password'], $admin->password_hash)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $admin->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been deactivated.'],
            ]);
        }

        return $admin->createToken($device)->plainTextToken;
    }

    public function logoutUser(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function logoutAdmin(AdminUser $admin): void
    {
        $admin->currentAccessToken()->delete();
    }
}
