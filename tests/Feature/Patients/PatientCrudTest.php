<?php

namespace Tests\Feature\Patients;

use App\Models\Patient;
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
}
