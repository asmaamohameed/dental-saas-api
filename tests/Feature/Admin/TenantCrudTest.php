<?php

namespace Tests\Feature\Admin;

use App\Enums\TenantStatus;
use App\Models\AdminUser;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantCrudTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): AdminUser
    {
        $admin = AdminUser::factory()->create();
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    public function test_admin_can_list_tenants(): void
    {
        $this->actingAsAdmin();
        Tenant::factory()->count(3)->create();

        $this->getJson('/admin/tenants')->assertOk();
    }

    public function test_admin_can_create_a_tenant(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/admin/tenants', [
            'name' => 'Smile Clinic',
            'subdomain' => 'smile-clinic',
            'locale' => 'ar',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.subdomain', 'smile-clinic');

        $this->assertDatabaseHas('tenants', ['subdomain' => 'smile-clinic']);
    }

    public function test_creating_a_tenant_with_duplicate_subdomain_fails(): void
    {
        $this->actingAsAdmin();
        Tenant::factory()->create(['subdomain' => 'taken-subdomain']);

        $this->postJson('/admin/tenants', [
            'name' => 'Another Clinic',
            'subdomain' => 'taken-subdomain',
            'status' => 'active',
        ])->assertStatus(422)->assertJsonValidationErrors(['subdomain']);
    }

    /**
     * Regression test: "suspended" كانت مسموحة في الـ validation القديمة
     * بس مش case موجودة في TenantStatus خالص - كانت بتسبب 500 (ValueError)
     * بدل 422. بعد إصلاح Rule::enum لازم ترجع 422 نضيف.
     */
    public function test_creating_a_tenant_with_an_invalid_status_value_returns_a_validation_error_not_a_crash(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/admin/tenants', [
            'name' => 'Broken Clinic',
            'subdomain' => 'broken-clinic',
            'status' => 'suspended',
        ])->assertStatus(422)->assertJsonValidationErrors(['status']);
    }

    public function test_admin_can_view_a_single_tenant(): void
    {
        $this->actingAsAdmin();
        $tenant = Tenant::factory()->create();

        $this->getJson("/admin/tenants/{$tenant->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $tenant->id);
    }

    public function test_admin_can_update_a_tenant_status(): void
    {
        $this->actingAsAdmin();
        $tenant = Tenant::factory()->create(['status' => TenantStatus::ACTIVE]);

        $this->putJson("/admin/tenants/{$tenant->id}", [
            'status' => 'inactive',
        ])->assertOk()->assertJsonPath('data.status', 'inactive');

        $tenant->refresh();
        $this->assertSame(TenantStatus::INACTIVE, $tenant->status);
    }

    /**
     * apiResource(...)->except('destroy') - المفروض مفيش route للحذف خالص،
     * فإرسال DELETE على URI موجود لأفعال تانية لازم يرجع 405 مش 404.
     */
    public function test_there_is_no_delete_route_for_tenants(): void
    {
        $this->actingAsAdmin();
        $tenant = Tenant::factory()->create();

        $this->deleteJson("/admin/tenants/{$tenant->id}")->assertStatus(405);
    }
}
