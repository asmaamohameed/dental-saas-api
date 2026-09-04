<?php

namespace Tests\Feature\Tenancy;

use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_user_cannot_view_a_patient_belonging_to_another_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $patientB = Patient::factory()->create();

        $this->actingAsTenantUser();

        $this->getJson("/api/v1/patients/{$patientB->id}")
            ->assertNotFound();
    }

    public function test_patients_index_only_returns_current_tenant_patients(): void
    {
        $tenantA = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantA->id);
        $patientA = Patient::factory()->create();

        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        Patient::factory()->count(3)->create();

        $this->actingAsTenantUser($tenantA);

        $ids = collect(
            $this->getJson('/api/v1/patients')->assertOk()->json('data.items')
        )->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($patientA->id));
    }

    public function test_user_cannot_update_a_patient_belonging_to_another_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $patientB = Patient::factory()->create();

        $this->actingAsTenantUser();

        $this->putJson("/api/v1/patients/{$patientB->id}", ['full_name' => 'Hacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('patients', [
            'id' => $patientB->id,
            'full_name' => $patientB->full_name,
        ]);
    }

    public function test_user_cannot_delete_a_patient_belonging_to_another_tenant(): void
    {
        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $patientB = Patient::factory()->create();

        $this->actingAsTenantUser();

        $this->deleteJson("/api/v1/patients/{$patientB->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('patients', ['id' => $patientB->id]);
    }

    public function test_user_belonging_to_inactive_tenant_is_forbidden(): void
    {
        $tenant = Tenant::factory()->inactive()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $user = User::factory()->doctor()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/patients')->assertForbidden();
    }
}
