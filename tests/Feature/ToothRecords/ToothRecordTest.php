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

    public function test_a_new_tooth_record_does_not_delete_or_overwrite_previous_ones(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $this->postJson("/api/v1/patients/{$patient->id}/tooth-records", $this->validPayload($appointment->id, [
            'condition' => 'decayed',
        ]))->assertCreated();

        $this->postJson("/api/v1/patients/{$patient->id}/tooth-records", $this->validPayload($appointment->id, [
            'condition' => 'filled',
        ]))->assertCreated();

        $this->assertDatabaseCount('tooth_records', 2);
        $this->assertDatabaseHas('tooth_records', ['tooth_number' => 11, 'condition' => 'decayed']);
        $this->assertDatabaseHas('tooth_records', ['tooth_number' => 11, 'condition' => 'filled']);
    }

    public function test_odontogram_returns_only_the_latest_record_per_tooth(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();
        $appointment = Appointment::factory()->create(['patient_id' => $patient->id]);

        $this->postJson("/api/v1/patients/{$patient->id}/tooth-records", $this->validPayload($appointment->id, [
            'condition' => 'decayed',
        ]))->assertCreated();

        $this->postJson("/api/v1/patients/{$patient->id}/tooth-records", $this->validPayload($appointment->id, [
            'condition' => 'filled',
        ]))->assertCreated();

        $response = $this->getJson("/api/v1/patients/{$patient->id}/odontogram")->assertOk();

        $records = collect($response->json('data'));

        $this->assertCount(1, $records->where('tooth_number', 11));
        $this->assertSame('filled', $records->firstWhere('tooth_number', 11)['condition']);
    }
}
