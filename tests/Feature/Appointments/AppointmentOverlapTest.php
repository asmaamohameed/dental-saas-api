<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentOverlapTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $owner;

    protected User $doctor;

    protected User $otherDoctor;

    protected Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        app(CurrentTenant::class)->set($this->tenant->id);

        $this->owner = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::OWNER,
        ]);

        $this->doctor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::DOCTOR,
        ]);

        $this->otherDoctor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::DOCTOR,
        ]);

        $this->patient = Patient::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Sanctum::actingAs($this->owner, ['*']);
    }

    protected function makeAppointment(array $overrides = []): Appointment
    {
        return Appointment::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'created_by' => $this->owner->id,
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
        ], $overrides));
    }

    public function test_cannot_book_the_exact_same_slot_with_the_same_doctor(): void
    {
        $this->makeAppointment([
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 30,
        ]);

        $response = $this->postJson('/api/v1/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'scheduled_at' => '2026-10-05 09:00:00',
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_cannot_book_a_slot_that_partially_overlaps_an_existing_one(): void
    {
        // Existing appointment: 9:00 - 9:30
        $this->makeAppointment([
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 30,
        ]);

        // New appointment starts at 9:15, ends 9:45 - overlaps
        $response = $this->postJson('/api/v1/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'scheduled_at' => '2026-10-05 09:15:00',
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_cannot_book_a_slot_fully_contained_inside_an_existing_longer_one(): void
    {
        // Existing appointment: 9:00 - 10:00
        $this->makeAppointment([
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 60,
        ]);

        // New appointment: 9:30 - 9:45, fully inside the existing one
        $response = $this->postJson('/api/v1/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'scheduled_at' => '2026-10-05 09:30:00',
            'duration_minutes' => 15,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_can_book_the_same_slot_with_a_different_doctor(): void
    {
        $this->makeAppointment([
            'doctor_id' => $this->doctor->id,
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 30,
        ]);

        $response = $this->postJson('/api/v1/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->otherDoctor->id,
            'scheduled_at' => '2026-10-05 09:00:00',
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(201);
    }

    public function test_can_book_a_slot_right_after_an_existing_one_ends(): void
    {
        // Existing: 9:00 - 9:30
        $this->makeAppointment([
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 30,
        ]);

        // New: starts exactly at 9:30 - no overlap
        $response = $this->postJson('/api/v1/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'scheduled_at' => '2026-10-05 09:30:00',
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(201);
    }

    public function test_a_cancelled_appointment_does_not_block_the_same_slot(): void
    {
        $this->makeAppointment([
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::CANCELLED,
        ]);

        $response = $this->postJson('/api/v1/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'scheduled_at' => '2026-10-05 09:00:00',
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(201);
    }

    public function test_updating_an_appointment_to_its_own_current_time_does_not_conflict_with_itself(): void
    {
        $appointment = $this->makeAppointment([
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 30,
        ]);

        $response = $this->putJson("/api/v1/appointments/{$appointment->id}", [
            'notes' => 'Updated notes, same time slot',
        ]);

        $response->assertStatus(200);
    }

    public function test_updating_an_appointment_into_another_appointments_slot_is_rejected(): void
    {
        $this->makeAppointment([
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 30,
        ]);

        $second = $this->makeAppointment([
            'scheduled_at' => Carbon::parse('2026-10-05 11:00:00'),
            'duration_minutes' => 30,
        ]);

        $response = $this->putJson("/api/v1/appointments/{$second->id}", [
            'scheduled_at' => '2026-10-05 09:00:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_a_different_tenants_appointment_at_the_same_time_does_not_block_booking(): void
    {
        $otherTenant = Tenant::factory()->create();

        $otherTenantDoctor = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role' => UserRole::DOCTOR,
        ]);

        app(CurrentTenant::class)->set($otherTenant->id);
        $otherTenantPatient = Patient::factory()->create(['tenant_id' => $otherTenant->id]);

        Appointment::factory()->create([
            'tenant_id' => $otherTenant->id,
            'patient_id' => $otherTenantPatient->id,
            'doctor_id' => $otherTenantDoctor->id,
            'created_by' => $otherTenantDoctor->id,
            'scheduled_at' => Carbon::parse('2026-10-05 09:00:00'),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        // Switch back to the original tenant context for the actual request
        app(CurrentTenant::class)->set($this->tenant->id);

        $response = $this->postJson('/api/v1/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'scheduled_at' => '2026-10-05 09:00:00',
            'duration_minutes' => 30,
        ]);

        $response->assertStatus(201);
    }
}
