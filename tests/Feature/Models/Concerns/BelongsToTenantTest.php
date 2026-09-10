<?php

namespace Tests\Feature\Models\Concerns;

use App\Models\Service;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BelongsToTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_querying_a_tenant_scoped_model_without_tenant_context_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without a tenant context');

        Service::query()->get();
    }

    public function test_creating_a_tenant_scoped_model_without_tenant_context_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('without a tenant context');

        Service::factory()->create();
    }

    public function test_creating_a_model_auto_assigns_the_current_tenant_id(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $service = Service::factory()->create();

        $this->assertSame($tenant->id, $service->tenant_id);
    }

    public function test_the_global_scope_prevents_seeing_another_tenants_records(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        app(CurrentTenant::class)->set($tenantA->id);
        $serviceA = Service::factory()->create();

        app(CurrentTenant::class)->set($tenantB->id);
        $serviceB = Service::factory()->create();

        $visibleIds = Service::query()->pluck('id')->all();

        $this->assertContains($serviceB->id, $visibleIds);
        $this->assertNotContains($serviceA->id, $visibleIds);
    }

    public function test_tenant_relation_returns_the_owning_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);

        $service = Service::factory()->create();

        $this->assertTrue($service->tenant->is($tenant));
    }
}
