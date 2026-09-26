<?php

namespace Tests\Unit\Policies;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;
use App\Policies\PatientPolicy;
use Tests\TestCase;

class PatientPolicyTest extends TestCase
{
    private PatientPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new PatientPolicy;
    }

    private function user(UserRole $role, string $tenantId = 'tenant-a'): User
    {
        return User::factory()->make(['role' => $role, 'tenant_id' => $tenantId]);
    }

    private function patient(string $tenantId = 'tenant-a'): Patient
    {
        return Patient::factory()->make(['tenant_id' => $tenantId]);
    }

    public function test_before_grants_owner_every_ability(): void
    {
        $this->assertTrue($this->policy->before($this->user(UserRole::OWNER), 'delete'));
    }

    public function test_before_defers_for_non_owner(): void
    {
        $this->assertNull($this->policy->before($this->user(UserRole::DOCTOR), 'view'));
    }

    public function test_view_any_allows_only_doctor_and_receptionist(): void
    {
        $this->assertTrue($this->policy->viewAny($this->user(UserRole::DOCTOR)));
        $this->assertTrue($this->policy->viewAny($this->user(UserRole::ASSISTANT)));
        $this->assertTrue($this->policy->viewAny($this->user(UserRole::RECEPTIONIST)));
    }

    public function test_view_denies_cross_tenant_access(): void
    {
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a');

        $this->assertTrue($this->policy->view($doctor, $this->patient('tenant-a')));
        $this->assertFalse($this->policy->view($doctor, $this->patient('tenant-b')));
    }

    public function test_view_medical_history_is_doctor_only(): void
    {
        $this->assertTrue($this->policy->viewMedicalHistory($this->user(UserRole::DOCTOR)));
        $this->assertTrue($this->policy->viewMedicalHistory($this->user(UserRole::ASSISTANT)));
        $this->assertFalse($this->policy->viewMedicalHistory($this->user(UserRole::RECEPTIONIST)));
    }

    public function test_create_allows_owner_doctor_and_receptionist(): void
    {
        $this->assertTrue($this->policy->create($this->user(UserRole::OWNER)));
        $this->assertTrue($this->policy->create($this->user(UserRole::DOCTOR)));
        $this->assertTrue($this->policy->create($this->user(UserRole::ASSISTANT)));
        $this->assertTrue($this->policy->create($this->user(UserRole::RECEPTIONIST)));
    }

    public function test_update_denies_cross_tenant_access(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');

        $this->assertFalse($this->policy->update($receptionist, $this->patient('tenant-b')));
    }

    public function test_delete_is_owner_only_and_same_tenant(): void
    {
        $owner = $this->user(UserRole::OWNER, 'tenant-a');
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a');

        $this->assertTrue($this->policy->delete($owner, $this->patient('tenant-a')));
        $this->assertFalse($this->policy->delete($owner, $this->patient('tenant-b')));
        $this->assertFalse($this->policy->delete($doctor, $this->patient('tenant-a')));
    }
}
