<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (AdminUser::query()->exists()) {
            return;
        }

        $email = config('platform.admin_email');
        $password = config('platform.admin_password');
        $name = config('platform.admin_name', 'Platform Administrator');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            if (! app()->environment('local')) {
                throw new RuntimeException('Set ADMIN_EMAIL to a valid address before seeding the platform admin.');
            }

            $email = 'superadmin@dental.com';
        }

        if (! is_string($password) || $password === '') {
            if (! app()->environment('local')) {
                throw new RuntimeException('Set ADMIN_PASSWORD before seeding the platform admin.');
            }

            $password = 'Password123';
        }

        AdminUser::query()->create([
            'name' => is_string($name) && $name !== '' ? $name : 'Platform Administrator',
            'email' => strtolower($email),
            'password_hash' => Hash::make($password),
            'is_active' => true,
        ]);
    }
}
