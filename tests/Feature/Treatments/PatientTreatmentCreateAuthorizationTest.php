<?php

namespace Tests\Feature\Treatments;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentScope;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientTreatmentCreateAuthorizationTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    public function test_doctor_create_assigns_authenticated_doctor(): void
    {
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($doctor, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate(['default_price' => 120]);

        $this->postJson("/api/v1/patients/{$patient->id}/treatments", [
            'service_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.dentist_id', $doctor->id)
            ->assertJsonPath('data.agreed_price', 120)
            ->assertJsonCount(1, 'data.teeth')
            ->assertJsonPath('data.teeth.0.tooth_number', '16');
    }

    public function test_owner_create_assigns_authenticated_owner_as_dentist(): void
    {
        $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($owner, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['21'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.dentist_id', $owner->id);
    }

    public function test_doctor_session_without_dentist_id_defaults_to_authenticated_doctor_not_treatment_dentist(): void
    {
        $doctorA = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $doctorB = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);

        Sanctum::actingAs($doctorA, ['*']);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['21'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.dentist_id', $doctorA->id)
            ->json('data.id');

        Sanctum::actingAs($doctorB, ['*']);

        $this->postJson("/api/v1/patient-treatments/{$treatmentId}/sessions", [
            'status' => 'scheduled',
        ])
            ->assertCreated()
            ->assertJsonPath('data.session.dentist_id', $doctorB->id);
    }

    public function test_owner_session_without_dentist_id_inherits_treatment_dentist_and_rejects_inactive_explicit_dentist(): void
    {
        $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($owner, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['21'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.dentist_id', $owner->id)
            ->json('data.id');

        $this->postJson("/api/v1/patient-treatments/{$treatmentId}/sessions", [
            'status' => 'scheduled',
        ])
            ->assertCreated()
            ->assertJsonPath('data.session.dentist_id', $owner->id);

        $inactiveDoctor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::DOCTOR,
            'is_active' => false,
        ]);

        $this->postJson("/api/v1/patient-treatments/{$treatmentId}/sessions", [
            'dentist_id' => $inactiveDoctor->id,
            'status' => 'scheduled',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dentist_id']);
    }

    public function test_receptionist_with_appointment_uses_appointment_doctor(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($receptionist, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
        ]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'appointment_id' => $appointment->id,
            'tooth_numbers' => ['36'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.dentist_id', $doctor->id);
    }

    public function test_receptionist_without_appointment_returns_422(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        Sanctum::actingAs($receptionist, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['36'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_assistant_without_appointment_returns_422(): void
    {
        $assistant = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::ASSISTANT]);
        Sanctum::actingAs($assistant, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_appointment_for_another_patient_is_rejected(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        Sanctum::actingAs($receptionist, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $otherPatient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $template = $this->clinicalTemplate();

        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $otherPatient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
        ]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'appointment_id' => $appointment->id,
            'tooth_numbers' => ['16'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_cancelled_appointment_is_rejected_for_dentist_resolution(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        Sanctum::actingAs($receptionist, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $template = $this->clinicalTemplate();

        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::CANCELLED,
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
        ]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'appointment_id' => $appointment->id,
            'tooth_numbers' => ['16'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_no_show_appointment_is_rejected_for_dentist_resolution(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        Sanctum::actingAs($receptionist, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $template = $this->clinicalTemplate();

        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::NO_SHOW,
            'scheduled_at' => now()->addDay(),
            'duration_minutes' => 30,
        ]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'appointment_id' => $appointment->id,
            'tooth_numbers' => ['16'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_client_sent_doctor_id_is_rejected(): void
    {
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $otherDoctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($doctor, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'doctor_id' => $otherDoctor->id,
            'tooth_numbers' => ['16'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['doctor_id']);
    }

    public function test_client_sent_dentist_id_is_rejected(): void
    {
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        $otherDoctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($doctor, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'dentist_id' => $otherDoctor->id,
            'tooth_numbers' => ['16'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['dentist_id']);
    }

    public function test_cross_tenant_appointment_is_rejected(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        Sanctum::actingAs($receptionist, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $foreignAppointment = Appointment::factory()->create([
            'tenant_id' => $otherTenant->id,
            'patient_id' => Patient::factory()->create(['tenant_id' => $otherTenant->id])->id,
        ]);
        app(CurrentTenant::class)->set($this->tenant->id);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'appointment_id' => $foreignAppointment->id,
            'tooth_numbers' => ['16'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['appointment_id']);
    }

    public function test_receptionist_cannot_create_with_custom_agreed_price_without_permission(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($receptionist, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate(['default_price' => 100]);
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
        ]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'appointment_id' => $appointment->id,
            'tooth_numbers' => ['16'],
            'agreed_price' => 150,
        ])->assertForbidden();
    }

    public function test_receptionist_can_create_when_agreed_price_matches_template_default(): void
    {
        $receptionist = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::RECEPTIONIST]);
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($receptionist, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate(['default_price' => 100]);
        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
        ]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'appointment_id' => $appointment->id,
            'tooth_numbers' => ['16'],
            'agreed_price' => 100,
        ])
            ->assertCreated()
            ->assertJsonPath('data.agreed_price', 100);
    }

    public function test_doctor_can_create_with_custom_agreed_price(): void
    {
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($doctor, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate(['default_price' => 100]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
            'agreed_price' => 150,
        ])
            ->assertCreated()
            ->assertJsonPath('data.agreed_price', 150);
    }

    public function test_agreed_price_defaults_to_template_default_price(): void
    {
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($doctor, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate(['default_price' => 275]);

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.agreed_price', 275);
    }

    public function test_decimal_agreed_price_is_rejected_on_create(): void
    {
        $doctor = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::DOCTOR]);
        Sanctum::actingAs($doctor, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->clinicalTemplate();

        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['16'],
            'agreed_price' => 99.5,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['agreed_price']);
    }

    public function test_template_store_defaults_scope_to_tooth(): void
    {
        $owner = User::factory()->create(['tenant_id' => $this->tenant->id, 'role' => UserRole::OWNER]);
        Sanctum::actingAs($owner, ['*']);

        $response = $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'خدمة',
            'name_en' => 'Scope Default '.uniqid(),
            'category' => 'medical',
            'default_price' => 50,
            'estimated_duration_minutes' => 30,
        ])->assertCreated();

        $this->assertSame('tooth', $response->json('data.scope'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function clinicalTemplate(array $overrides = []): TreatmentTemplate
    {
        return TreatmentTemplate::query()->create(array_merge([
            'name_ar' => 'علاج',
            'name_en' => 'Clinical '.uniqid(),
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ], $overrides));
    }
}
