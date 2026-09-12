<?php

namespace Tests\Feature\Appointments;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class AppointmentValidationTest extends TestCase
{
    public function test_cannot_create_an_appointment_with_a_patient_from_another_tenant(): void
    {
        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignPatient = Patient::factory()->create();

        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $doctor = User::factory()->doctor()->create();

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $foreignPatient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_id');
    }

    public function test_cannot_create_an_appointment_with_a_doctor_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignDoctor = User::factory()->doctor()->create();

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $foreignDoctor->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');
    }

    public function test_cannot_create_an_appointment_with_a_non_doctor_as_doctor_id(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $receptionist = User::factory()->receptionist()->create();

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $receptionist->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');
    }

    public function test_cannot_reassign_an_appointment_to_a_doctor_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $appointment = Appointment::factory()->create();

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignDoctor = User::factory()->doctor()->create();

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->putJson("/api/v1/appointments/{$appointment->id}", [
            'doctor_id' => $foreignDoctor->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');
    }
}
