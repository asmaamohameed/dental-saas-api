<?php

namespace Tests\Feature\Admin;

use App\Models\AdminUser;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_can_access_admin_routes(): void
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        $this->getJson('/admin/ping')->assertOk();
    }

    public function test_regular_tenant_user_cannot_access_admin_routes(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/admin/ping')->assertForbidden();
        $this->getJson('/admin/tenants')->assertForbidden();
    }

    public function test_unauthenticated_request_cannot_access_admin_routes(): void
    {
        $this->getJson('/admin/ping')->assertUnauthorized();
    }
}
