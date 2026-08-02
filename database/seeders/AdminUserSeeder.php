<?php

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        AdminUser::firstOrCreate([
            'email' => 'superadmin@dental.com',
        ], [
            'name' => 'Super Administrator',
            'password_hash' => Hash::make('password'),
            'is_active' => true,
        ]);
    }
}
