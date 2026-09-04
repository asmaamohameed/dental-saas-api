<?php

namespace Tests\Feature\Appointments;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Tests\TestCase;

class AppointmentCrudTest extends TestCase
{
    public function test_an_appointment_can_be_created(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create();

        $response = $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 30,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => 'scheduled',
        ]);
    }

    public function test_an_appointment_can_be_updated(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $appointment = Appointment::factory()->create(['duration_minutes' => 30]);

        $response = $this->putJson("/api/v1/appointments/{$appointment->id}", [
            'duration_minutes' => 45,
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'duration_minutes' => 45,
        ]);
    }
}
