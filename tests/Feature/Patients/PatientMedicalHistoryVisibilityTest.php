<?php

namespace Tests\Feature\Patients;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PatientMedicalHistoryVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        app(CurrentTenant::class)->set($this->tenant->id);

        $this->patient = Patient::factory()->create([
            'tenant_id' => $this->tenant->id,
            'medical_history' => 'Diabetes type 2, on Metformin',
            'notes' => 'Anxious during procedures - prefers morning slots',
        ]);
    }

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => $role,
        ]);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function showPatientUrl(): string
    {
        return "/api/v1/patients/{$this->patient->id}";
    }

    public function test_owner_can_see_medical_history_and_notes(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $response = $this->getJson($this->showPatientUrl());

        $response->assertOk();
        $response->assertJsonPath('data.medical_history', 'Diabetes type 2, on Metformin');
        $response->assertJsonPath('data.notes', 'Anxious during procedures - prefers morning slots');
    }

    public function test_doctor_can_see_medical_history_and_notes(): void
    {
        $this->actingAsRole(UserRole::DOCTOR);

        $response = $this->getJson($this->showPatientUrl());

        $response->assertOk();
        $response->assertJsonPath('data.medical_history', 'Diabetes type 2, on Metformin');
        $response->assertJsonPath('data.notes', 'Anxious during procedures - prefers morning slots');
    }

    public function test_assistant_can_see_medical_history_and_notes(): void
    {
        $this->actingAsRole(UserRole::ASSISTANT);

        $response = $this->getJson($this->showPatientUrl());

        $response->assertOk();
        $response->assertJsonPath('data.medical_history', 'Diabetes type 2, on Metformin');
        $response->assertJsonPath('data.notes', 'Anxious during procedures - prefers morning slots');
    }

    public function test_receptionist_cannot_see_medical_history_or_notes(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);

        $response = $this->getJson($this->showPatientUrl());

        $response->assertOk();
        $response->assertJsonPath('data.medical_history', null);
        $response->assertJsonPath('data.notes', null);
    }

    public function test_receptionist_can_still_see_other_non_sensitive_patient_fields(): void
    {
        $this->actingAsRole(UserRole::RECEPTIONIST);

        $response = $this->getJson($this->showPatientUrl());

        $response->assertOk();
        $response->assertJsonPath('data.full_name', $this->patient->full_name);
        $response->assertJsonPath('data.phone', $this->patient->phone);
    }

    public function test_guest_gets_null_medical_history_and_notes(): void
    {
        $response = $this->getJson($this->showPatientUrl());

        $response->assertUnauthorized();
    }
}
