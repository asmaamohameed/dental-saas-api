<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Events\PatientCheckedIn;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AppointmentStatusTransitionTest extends TestCase
{
    public function test_valid_lifecycle_scheduled_to_completed_via_in_progress(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $appointment = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk()
            ->assertJsonPath('data.status', 'checked_in');

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'in_progress',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::COMPLETED, $appointment->status);
    }

    public function test_invalid_status_jumps_return_422_and_do_not_persist(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);

        $scheduled = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$scheduled->id}/status", [
            'status' => 'completed',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $scheduled->refresh();
        $this->assertSame(AppointmentStatus::SCHEDULED, $scheduled->status);

        $this->patchJson("/api/v1/appointments/{$scheduled->id}/status", [
            'status' => 'in_progress',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $scheduled->refresh();
        $this->assertSame(AppointmentStatus::SCHEDULED, $scheduled->status);

        $owner = User::factory()->owner()->create(['tenant_id' => $doctor->tenant_id]);
        $completed = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::COMPLETED,
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($owner, ['*']);

        $this->patchJson("/api/v1/appointments/{$completed->id}/status", [
            'status' => 'in_progress',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $completed->refresh();
        $this->assertSame(AppointmentStatus::COMPLETED, $completed->status);

        $cancelled = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::CANCELLED,
        ]);

        \Laravel\Sanctum\Sanctum::actingAs($doctor, ['*']);

        $this->patchJson("/api/v1/appointments/{$cancelled->id}/status", [
            'status' => 'checked_in',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $cancelled->refresh();
        $this->assertSame(AppointmentStatus::CANCELLED, $cancelled->status);
    }

    public function test_same_status_request_is_idempotent_and_does_not_redispatch_patient_checked_in(): void
    {
        Event::fake([PatientCheckedIn::class]);

        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $appointment = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        Event::assertDispatched(PatientCheckedIn::class, 1);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk()
            ->assertJsonPath('data.status', 'checked_in');

        Event::assertDispatched(PatientCheckedIn::class, 1);
    }

    public function test_doctor_from_another_tenant_cannot_update_appointment_status(): void
    {
        $tenantA = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantA->id);
        $doctorA = User::factory()->doctor()->create(['tenant_id' => $tenantA->id]);
        $appointment = Appointment::factory()->create([
            'tenant_id' => $tenantA->id,
            'doctor_id' => $doctorA->id,
            'status' => AppointmentStatus::CHECKED_IN,
        ]);

        $tenantB = Tenant::factory()->create();
        $this->actingAsTenantUser($tenantB, UserRole::DOCTOR);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'in_progress',
        ])->assertNotFound();
    }

    public function test_different_doctor_in_same_tenant_cannot_update_status_to_in_progress(): void
    {
        $otherDoctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $assignedDoctor = User::factory()->doctor()->create();

        $appointment = Appointment::factory()->create([
            'doctor_id' => $assignedDoctor->id,
            'status' => AppointmentStatus::CHECKED_IN,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'in_progress',
        ])->assertForbidden();

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::CHECKED_IN, $appointment->status);
    }
}
