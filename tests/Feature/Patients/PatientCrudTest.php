<?php

namespace Tests\Feature\Patients;

use App\Models\Patient;
use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class PatientCrudTest extends TestCase
{
    public function test_a_patient_can_be_created(): void
    {
        $this->actingAsTenantUser();

        $response = $this->postJson('/api/v1/patients', [
            'full_name' => 'Ahmed Mostafa',
            'phone' => '01012345678',
            'date_of_birth' => '1990-05-20',
            'gender' => 'male',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.full_name', 'Ahmed Mostafa');
        $response->assertJsonPath('data.phone', '01012345678');
        $response->assertJsonPath('data.gender', 'male');

        $this->assertDatabaseHas('patients', [
            'full_name' => 'Ahmed Mostafa',
            'phone' => '01012345678',
        ]);
    }

    public function test_a_patient_can_be_updated(): void
    {
        $this->actingAsTenantUser();
        $patient = Patient::factory()->create(['full_name' => 'Old Name']);

        $response = $this->putJson("/api/v1/patients/{$patient->id}", [
            'full_name' => 'New Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.full_name', 'New Name');

        $this->assertDatabaseHas('patients', [
            'id' => $patient->id,
            'full_name' => 'New Name',
        ]);
    }

    public function test_phone_must_be_unique_within_the_same_tenant(): void
    {
        $this->actingAsTenantUser();

        Patient::factory()->create(['phone' => '01099998888']);

        $response = $this->postJson('/api/v1/patients', [
            'full_name' => 'Duplicate Phone',
            'phone' => '01099998888',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_the_same_phone_can_exist_in_a_different_tenant(): void
    {
        $this->actingAsTenantUser();

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        Patient::factory()->create([
            'tenant_id' => $otherTenant->id,
            'phone' => '01077776666',
        ]);

        $this->actingAsTenantUser();

        $response = $this->postJson('/api/v1/patients', [
            'full_name' => 'Same Phone Other Tenant',
            'phone' => '01077776666',
        ]);

        $response->assertCreated();
    }

    public function test_updating_a_patient_can_keep_the_same_phone(): void
    {
        $this->actingAsTenantUser();
        $patient = Patient::factory()->create(['phone' => '01055554444']);

        $response = $this->putJson("/api/v1/patients/{$patient->id}", [
            'phone' => '01055554444',
            'full_name' => 'Renamed',
        ]);

        $response->assertOk();
    }

    public function test_updating_a_patient_to_another_patients_phone_is_rejected(): void
    {
        $this->actingAsTenantUser();
        Patient::factory()->create(['phone' => '01033332222']);
        $patient = Patient::factory()->create(['phone' => '01011110000']);

        $response = $this->putJson("/api/v1/patients/{$patient->id}", [
            'phone' => '01033332222',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }
}
