<?php

namespace Tests\Feature\ToothRecords;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use Tests\TestCase;

class ToothRecordTest extends TestCase
{
    protected function validPayload(string $appointmentId, array $overrides = []): array
    {
        return array_merge([
            'appointment_id' => $appointmentId,
            'tooth_number' => '11',
            'condition' => 'decayed',
            'treatment_status' => 'planned',
        ], $overrides);
    }

    public function test_receptionist_cannot_create_a_tooth_record(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $this->postJson("/api/v1/patients/{$patient->id}/tooth-records", $this->validPayload($appointment->id))
            ->assertForbidden();
    }

    public function test_receptionist_cannot_view_the_odontogram(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();

        $this->getJson("/api/v1/patients/{$patient->id}/odontogram")
            ->assertForbidden();
    }

    public function test_create_tooth_record_returns_gone(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $this->postJson("/api/v1/patients/{$patient->id}/tooth-records", $this->validPayload($appointment->id))
            ->assertStatus(410)
            ->assertJsonValidationErrors(['tooth_records']);

        $this->assertDatabaseCount('tooth_records', 0);
    }

    public function test_odontogram_returns_derived_payload_shape(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();

        $this->getJson("/api/v1/patients/{$patient->id}/odontogram")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'records',
                    'treatment_status_by_tooth',
                    'teeth',
                ],
            ])
            ->assertJsonPath('data.records', []);
    }
}
