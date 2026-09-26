<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminAccessSeeder extends Seeder
{
    public function run(): void
    {
        $tenantId = Str::uuid()->toString();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'subdomain' => 'clinic-1',
            'name' => 'Clinic 1',
            'status' => 'active',
        ]);

        DB::table('subscriptions')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'plan_type' => 'Premium',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        DB::table('users')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'name' => 'Owner',
            'email' => 'owner@clinic.com',
            'phone' => '+201000000000',
            'password_hash' => Hash::make('password'),
            'role' => 'owner',
            'is_active' => true,
        ]);

        $this->command->info('Tenant + owner user created. Login: owner@clinic.com / password');
    }
}
