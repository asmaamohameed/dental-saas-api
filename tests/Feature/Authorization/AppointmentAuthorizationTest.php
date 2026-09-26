<?php

namespace Tests\Feature\Authorization;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Tests\TestCase;

class AppointmentAuthorizationTest extends TestCase
{
    public function test_doctor_can_update_an_appointment(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $appointment = Appointment::factory()->create(['doctor_id' => $doctor->id]);

        $this->putJson("/api/v1/appointments/{$appointment->id}", [
            'duration_minutes' => 45,
        ])->assertOk();
    }

    public function test_doctor_can_update_status_of_their_own_appointment(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $appointment = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::CHECKED_IN,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'completed',
        ])->assertOk();
    }

    public function test_doctor_can_update_status_of_another_doctors_appointment(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $otherDoctor = User::factory()->doctor()->create();
        $appointment = Appointment::factory()->create([
            'doctor_id' => $otherDoctor->id,
            'status' => AppointmentStatus::CHECKED_IN,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'completed',
        ])->assertOk();
    }

    public function test_receptionist_cannot_delete_an_appointment(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $appointment = Appointment::factory()->create();

        $this->deleteJson("/api/v1/appointments/{$appointment->id}")
            ->assertForbidden();
    }

    public function test_owner_can_delete_an_appointment(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $appointment = Appointment::factory()->create(['status' => AppointmentStatus::SCHEDULED]);
        $this->deleteJson("/api/v1/appointments/{$appointment->id}")
            ->assertOk();
    }

    public function test_owner_cannot_delete_a_completed_appointment(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $appointment = Appointment::factory()->create(['status' => AppointmentStatus::COMPLETED]);

        $this->deleteJson("/api/v1/appointments/{$appointment->id}")
            ->assertForbidden();
    }

    public function test_doctor_can_create_an_appointment(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $otherDoctor = User::factory()->doctor()->create();

        $this->actingAsTenantUser($tenant, UserRole::DOCTOR);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $otherDoctor->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'duration_minutes' => 30,
        ])->assertCreated();
    }
}
