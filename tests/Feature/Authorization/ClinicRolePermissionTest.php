<?php

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Tenant;
use App\Support\Roles\ClinicPermissionCatalog;
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class ClinicRolePermissionTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    public function test_owner_can_read_the_editable_roles(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);

        $this->getJson('/api/v1/role-permissions')
            ->assertOk()
            ->assertJsonPath('data.roles.0.name', 'doctor')
            ->assertJsonPath('data.roles.1.name', 'assistant')
            ->assertJsonPath('data.roles.2.name', 'receptionist')
            ->assertJsonPath('data.roles.0.permissions.staff:view', false);
    }

    public function test_doctor_cannot_change_role_permissions(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);

        $this->getJson('/api/v1/role-permissions')->assertForbidden();
        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => ['patient:view' => true],
        ])->assertForbidden();
    }

    public function test_owner_role_cannot_be_edited(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);

        $this->putJson('/api/v1/role-permissions/owner', [
            'permissions' => ['patient:view' => false],
        ])->assertStatus(422);
    }

    public function test_owner_can_grant_staff_view_to_a_doctor(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);
        $this->getJson('/api/v1/staff')->assertForbidden();

        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['doctor'];
        $permissions['staff:view'] = true;

        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => $permissions,
        ])->assertOk()
            ->assertJsonPath('data.roles.0.permissions.staff:view', true);

        $this->actingAsTenantUser($this->tenant, UserRole::DOCTOR);
        $this->getJson('/api/v1/staff')->assertOk();
    }

    public function test_owner_can_revoke_patient_view_from_a_receptionist(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::RECEPTIONIST);
        Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->getJson('/api/v1/patients')->assertOk();

        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['receptionist'];
        $permissions['patient:view'] = false;

        $this->putJson('/api/v1/role-permissions/receptionist', [
            'permissions' => $permissions,
        ])->assertOk();

        $this->actingAsTenantUser($this->tenant, UserRole::RECEPTIONIST);
        $this->getJson('/api/v1/patients')->assertForbidden();
    }

    public function test_saving_the_baseline_does_not_change_existing_access(): void
    {
        $this->actingAsTenantUser($this->tenant, UserRole::OWNER);

        $this->putJson('/api/v1/role-permissions/assistant', [
            'permissions' => ClinicPermissionCatalog::baseline()['assistant'],
        ])->assertOk();

        $this->actingAsTenantUser($this->tenant, UserRole::ASSISTANT);
        $this->getJson('/api/v1/patients')->assertOk();
        $this->getJson('/api/v1/staff')->assertForbidden();
    }
}
