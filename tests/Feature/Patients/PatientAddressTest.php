<?php

namespace Tests\Feature\Patients;

use App\Models\Patient;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class PatientAddressTest extends TestCase
{
    public function test_address_is_stored_on_create(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/v1/patients', [
            'full_name' => 'Ahmed Mostafa',
            'phone' => '01012345679',
            'address' => '123 Nile Street, Cairo',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.address', '123 Nile Street, Cairo');

        $this->assertDatabaseHas('patients', [
            'full_name' => 'Ahmed Mostafa',
            'address' => '123 Nile Street, Cairo',
        ]);
    }

    public function test_address_can_be_null_on_create(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/v1/patients', [
            'full_name' => 'No Address Patient',
            'phone' => '01012345680',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.address', null);
    }

    public function test_address_can_be_updated(): void
    {
        $this->actingAsTenantUser();
        $patient = Patient::factory()->create(['address' => 'Old Address']);

        $response = $this->putJson("/api/v1/patients/{$patient->id}", [
            'address' => 'New Address',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.address', 'New Address');

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'address' => 'New Address',
        ]);
    }

    public function test_address_too_long_is_rejected(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/v1/patients', [
            'full_name' => 'Long Address',
            'phone' => '01012345681',
            'address' => str_repeat('a', 256),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['address']);
    }

    public function test_address_is_scoped_to_tenant_on_update(): void
    {
        $tenantA = Tenant::factory()->create();
        $this->actingAsTenantUser($tenantA);
        $patient = Patient::factory()->create([
            'tenant_id' => $tenantA->id,
            'address' => 'Tenant A Address',
        ]);

        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $otherPatient = Patient::factory()->create([
            'tenant_id' => $tenantB->id,
            'address' => 'Tenant B Address',
        ]);

        $this->actingAsTenantUser($tenantA);

        $response = $this->putJson("/api/v1/patients/{$patient->id}", [
            'address' => 'Updated In Tenant A',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'address' => 'Updated In Tenant A',
        ]);
        $this->assertDatabaseHas('patients', [
            'id' => $otherPatient->id,
            'address' => 'Tenant B Address',
        ]);
    }
}
