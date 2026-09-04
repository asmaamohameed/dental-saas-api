<?php

namespace Tests\Feature\Auth;

use App\Models\AdminUser;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminGuardSeparationTest extends TestCase
{
    public function test_tenant_user_cannot_access_admin_routes(): void
    {
        $this->actingAsTenantUser();

        $this->getJson('/admin/tenants')->assertForbidden();
    }

    public function test_admin_cannot_access_tenant_routes(): void
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/patients')->assertForbidden();
    }

    public function test_admin_can_access_admin_routes(): void
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin);

        $this->getJson('/admin/tenants')->assertOk();
    }
}
