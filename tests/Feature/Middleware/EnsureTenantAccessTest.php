<?php

namespace Tests\Feature\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnsureTenantAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_a_tenant_id_is_forbidden(): void
    {
        $user = User::factory()->make(['tenant_id' => null]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/patients')->assertStatus(403);
    }

    public function test_user_of_an_inactive_tenant_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::INACTIVE]);
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/patients')
            ->assertStatus(403)
            ->assertJson(['message' => 'Tenant account is inactive or suspended.']);
    }

    public function test_user_of_an_active_tenant_passes_through(): void
    {
        $tenant = Tenant::factory()->create(['status' => TenantStatus::ACTIVE]);
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/v1/patients')->assertOk();
    }
}
