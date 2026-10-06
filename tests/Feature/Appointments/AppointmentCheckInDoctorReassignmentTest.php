<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentSessionStatus;
use App\Enums\UserRole;
use App\Events\PatientCheckedIn;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Tenant;
use App\Models\TreatmentSession;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentCheckInDoctorReassignmentTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
        $this->patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_check_in_with_different_doctor_succeeds_despite_scheduled_overlap(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00 UTC');

        $bookedDoctor = User::factory()->doctor()->for($this->tenant)->create();
        $replacement = User::factory()->doctor()->for($this->tenant)->create();
        $receptionist = User::factory()->receptionist()->for($this->tenant)->create();

        $slot = Carbon::parse('2026-10-06 11:00:00');
        $otherPatient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $otherPatient->id,
            'doctor_id' => $replacement->id,
            'scheduled_at' => $slot,
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $bookedDoctor->id,
            'scheduled_at' => $slot,
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        Sanctum::actingAs($receptionist, ['*']);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
            'doctor_id' => $replacement->id,
        ])->assertOk()
            ->assertJsonPath('data.doctor_id', $replacement->id)
            ->assertJsonPath('data.booked_doctor_id', $bookedDoctor->id);

        Carbon::setTestNow();
    }

    public function test_check_in_without_doctor_id_is_unchanged(): void
    {
        $doctor = User::factory()->doctor()->for($this->tenant)->create();
        Sanctum::actingAs($doctor, ['*']);

        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk()
            ->assertJsonPath('data.doctor_id', $doctor->id)
            ->assertJsonPath('data.booked_doctor_id', null);
    }

    public function test_check_in_rejects_inactive_or_invalid_doctor(): void
    {
        $doctor = User::factory()->doctor()->for($this->tenant)->create();
        $receptionist = User::factory()->receptionist()->for($this->tenant)->create();
        $inactive = User::factory()->doctor()->for($this->tenant)->create(['is_active' => false]);
        $assistant = User::factory()->assistant()->for($this->tenant)->create();

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignDoctor = User::factory()->doctor()->for($otherTenant)->create();
        app(CurrentTenant::class)->set($this->tenant->id);

        Sanctum::actingAs($receptionist, ['*']);

        $inactiveAppointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$inactiveAppointment->id}/status", [
            'status' => 'checked_in',
            'doctor_id' => $inactive->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['doctor_id']);

        $foreignAppointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$foreignAppointment->id}/status", [
            'status' => 'checked_in',
            'doctor_id' => $foreignDoctor->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['doctor_id']);

        $assistantAppointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$assistantAppointment->id}/status", [
            'status' => 'checked_in',
            'doctor_id' => $assistant->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_non_check_in_transition_still_rejects_conflicting_doctor_id(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00 UTC');

        $doctor = User::factory()->doctor()->for($this->tenant)->create();
        $otherDoctor = User::factory()->doctor()->for($this->tenant)->create();
        Sanctum::actingAs($doctor, ['*']);

        $slot = Carbon::parse('2026-10-06 12:00:00');

        Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $otherDoctor->id,
            'scheduled_at' => $slot,
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $slot,
            'duration_minutes' => 30,
            'status' => AppointmentStatus::CHECKED_IN,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'in_progress',
            'doctor_id' => $otherDoctor->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['doctor_id']);

        Carbon::setTestNow();
    }

    public function test_patient_checked_in_dispatched_to_new_doctor_channel_once(): void
    {
        Event::fake([PatientCheckedIn::class]);

        $bookedDoctor = User::factory()->doctor()->for($this->tenant)->create();
        $replacement = User::factory()->doctor()->for($this->tenant)->create();
        $receptionist = User::factory()->receptionist()->for($this->tenant)->create();

        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $bookedDoctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        Sanctum::actingAs($receptionist, ['*']);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
            'doctor_id' => $replacement->id,
        ])->assertOk();

        Event::assertDispatched(PatientCheckedIn::class, 1);
        Event::assertDispatched(PatientCheckedIn::class, function (PatientCheckedIn $event) use ($replacement, $bookedDoctor): bool {
            $channelName = $event->broadcastOn()[0]->name;
            $expected = "private-tenant.{$replacement->tenant_id}.doctor.{$replacement->id}";

            return $event->doctorId === $replacement->id
                && $event->doctorId !== $bookedDoctor->id
                && $channelName === $expected;
        });
    }

    public function test_non_finalized_sessions_get_new_dentist_id_completed_sessions_unchanged(): void
    {
        $bookedDoctor = User::factory()->doctor()->for($this->tenant)->create();
        $replacement = User::factory()->doctor()->for($this->tenant)->create();
        $receptionist = User::factory()->receptionist()->for($this->tenant)->create();

        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $bookedDoctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $owner = User::factory()->owner()->for($this->tenant)->create();
        Sanctum::actingAs($owner, ['*']);

        $templateId = $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
            'category' => 'medical',
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'steps' => [[
                'step_order' => 1,
                'name_ar' => 'خطوة',
                'name_en' => 'Step',
                'default_duration_minutes' => 30,
                'is_required' => true,
                'is_repeatable' => false,
            ]],
        ])->assertCreated()->json('data.id');

        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $this->patient->id,
            'treatment_template_id' => $templateId,
            'agreed_price' => 100,
        ])->assertCreated()->json('data.id');

        $treatment = PatientTreatment::query()->findOrFail($treatmentId);

        Sanctum::actingAs($receptionist, ['*']);

        $scheduledSession = TreatmentSession::query()->create([
            'tenant_id' => $this->tenant->id,
            'patient_treatment_id' => $treatment->id,
            'appointment_id' => $appointment->id,
            'dentist_id' => $bookedDoctor->id,
            'session_date' => now()->toDateString(),
            'status' => TreatmentSessionStatus::SCHEDULED,
        ]);

        $completedSession = TreatmentSession::query()->create([
            'tenant_id' => $this->tenant->id,
            'patient_treatment_id' => $treatment->id,
            'appointment_id' => $appointment->id,
            'dentist_id' => $bookedDoctor->id,
            'session_date' => now()->toDateString(),
            'status' => TreatmentSessionStatus::COMPLETED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
            'doctor_id' => $replacement->id,
        ])->assertOk();

        $scheduledSession->refresh();
        $completedSession->refresh();

        $this->assertSame($replacement->id, $scheduledSession->dentist_id);
        $this->assertSame($bookedDoctor->id, $completedSession->dentist_id);
    }
}
