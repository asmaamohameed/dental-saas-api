<?php

namespace Tests\Feature\Middleware;

use App\Enums\UserRole;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnsureUserRoleTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(UserRole $role): User
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => $role]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_doctor_is_forbidden_on_an_owner_receptionist_only_route(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $this->postJson('/api/v1/services', [])
            ->assertStatus(403)
            ->assertJson(['message' => 'Forbidden. Insufficient role permissions.']);
    }

    public function test_receptionist_passes_the_role_gate(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/services', [])->assertStatus(422);
    }

    public function test_doctor_is_forbidden_on_an_owner_only_route(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);
        $service = Service::factory()->create();

        $this->deleteJson("/api/v1/services/{$service->id}")
            ->assertStatus(403);
    }

    public function test_receptionist_is_forbidden_on_an_owner_only_route(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);
        $service = Service::factory()->create();

        $this->deleteJson("/api/v1/services/{$service->id}")
            ->assertStatus(403);
    }
}
