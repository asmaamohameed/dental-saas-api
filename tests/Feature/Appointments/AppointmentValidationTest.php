<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentType;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
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
            'scheduled_at' => $this->scheduledAtWithinClinicHours(),
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
            'scheduled_at' => $this->scheduledAtWithinClinicHours(),
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
            'scheduled_at' => $this->scheduledAtWithinClinicHours(),
            'duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('doctor_id');
    }

    public function test_consultation_appointment_does_not_require_linked_treatments(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create();

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $this->scheduledAtWithinClinicHours(),
            'duration_minutes' => 30,
            'appointment_type' => AppointmentType::CONSULTATION->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.appointment_type', AppointmentType::CONSULTATION->value);
    }

    public function test_cannot_link_treatments_to_non_treatment_appointment_types(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create();

        $this->actingAsTenantUser($tenant, UserRole::OWNER);
        $templateId = $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'علاج',
            'name_en' => 'Treatment',
            'category' => 'medical',
            'scope' => 'tooth',
            'default_price' => 250,
            'estimated_duration_minutes' => 30,
        ])->assertCreated()->json('data.id');

        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $templateId,
            'agreed_price' => 100,
            'tooth_numbers' => ['16'],
        ])->assertCreated()->json('data.id');

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $this->scheduledAtWithinClinicHours(),
            'duration_minutes' => 30,
            'appointment_type' => AppointmentType::CONSULTATION->value,
            'patient_treatment_ids' => [$treatmentId],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('patient_treatment_ids');
    }

    public function test_decimal_appointment_fee_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create(['tenant_id' => $tenant->id]);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $tenant->id]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $this->scheduledAtWithinClinicHours(),
            'duration_minutes' => 30,
            'fee' => 150.75,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fee']);
    }

    public function test_cannot_book_a_doctor_on_a_day_outside_their_working_days(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create([
            'working_days' => ['saturday', 'monday'],
        ]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $sunday = Carbon::now('Africa/Cairo')->next(Carbon::SUNDAY)->setTime(10, 0);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $sunday->utc()->toIso8601String(),
            'duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');

        $this->assertDatabaseMissing('appointments', [
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
        ]);
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
