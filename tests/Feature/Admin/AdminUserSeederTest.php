<?php

namespace Tests\Feature\Admin;

use App\Enums\UserRole;
use App\Models\AdminUser;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Database\Seeders\AdminUserSeeder;
use RuntimeException;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    public function test_seeder_creates_exactly_one_admin_from_configuration(): void
    {
        config([
            'platform.admin_email' => 'platform-admin@example.com',
            'platform.admin_password' => 'a-long-admin-secret',
            'platform.admin_name' => 'Platform Administrator',
        ]);

        $this->seed(AdminUserSeeder::class);
        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, AdminUser::query()->count());
        $admin = AdminUser::query()->firstOrFail();
        $this->assertSame('platform-admin@example.com', $admin->email);
        $this->assertTrue($admin->is_active);
        $this->assertNotSame('a-long-admin-secret', $admin->password_hash);
    }

    public function test_seeder_does_not_promote_a_clinic_user_or_create_a_second_admin(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $clinicUser = User::factory()->owner()->create([
            'email' => 'platform-admin@example.com',
        ]);
        app(CurrentTenant::class)->clear();

        AdminUser::factory()->create(['email' => 'existing-admin@example.com']);

        config([
            'platform.admin_email' => 'platform-admin@example.com',
            'platform.admin_password' => 'a-long-admin-secret',
        ]);

        $this->seed(AdminUserSeeder::class);

        $this->assertSame(1, AdminUser::query()->count());
        $this->assertSame('existing-admin@example.com', AdminUser::query()->firstOrFail()->email);

        $clinicUser = User::query()->withoutGlobalScope('tenant')->findOrFail($clinicUser->id);
        $this->assertSame(UserRole::OWNER, $clinicUser->role);
        $this->assertNotSame(AdminUser::query()->firstOrFail()->id, $clinicUser->id);
    }

    public function test_seeder_refuses_to_invent_a_password(): void
    {
        config([
            'platform.admin_email' => 'platform-admin@example.com',
            'platform.admin_password' => null,
        ]);

        try {
            $this->seed(AdminUserSeeder::class);
            $this->fail('The seeder created an admin without ADMIN_PASSWORD.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_PASSWORD', $exception->getMessage());
        }

        $this->assertSame(0, AdminUser::query()->count());
    }
}
