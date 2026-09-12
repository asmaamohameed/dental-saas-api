<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\Patient;
use Tests\TestCase;

class PatientAuthorizationTest extends TestCase
{
    public function test_receptionist_cannot_delete_a_patient(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);
        $patient = Patient::factory()->create();

        $this->deleteJson("/api/v1/patients/{$patient->id}")
            ->assertForbidden();
    }

    public function test_doctor_cannot_delete_a_patient(): void
    {
        $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $patient = Patient::factory()->create();

        $this->deleteJson("/api/v1/patients/{$patient->id}")
            ->assertForbidden();
    }

    public function test_owner_can_delete_a_patient(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);
        $patient = Patient::factory()->create();

        $this->deleteJson("/api/v1/patients/{$patient->id}")
            ->assertOk();
    }
}
