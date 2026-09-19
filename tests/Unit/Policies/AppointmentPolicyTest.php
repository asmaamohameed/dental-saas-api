<?php

namespace Tests\Unit\Policies;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;
use App\Policies\AppointmentPolicy;
use Tests\TestCase;

class AppointmentPolicyTest extends TestCase
{
    private AppointmentPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AppointmentPolicy;
    }

    private function user(UserRole $role, string $tenantId = 'tenant-a', ?string $id = null): User
    {
        $user = User::factory()->make(['role' => $role, 'tenant_id' => $tenantId]);

        if ($id !== null) {
            $user->id = $id;
        }

        return $user;
    }

    private function appointment(string $tenantId = 'tenant-a', ?string $doctorId = null, AppointmentStatus $status = AppointmentStatus::SCHEDULED): Appointment
    {
        $appointment = new Appointment;
        $appointment->tenant_id = $tenantId;
        $appointment->doctor_id = $doctorId;
        $appointment->status = $status;

        return $appointment;
    }

    public function test_view_any_allows_owner_doctor_and_receptionist(): void
    {
        $this->assertTrue($this->policy->viewAny($this->user(UserRole::OWNER)));
        $this->assertTrue($this->policy->viewAny($this->user(UserRole::DOCTOR)));
        $this->assertTrue($this->policy->viewAny($this->user(UserRole::RECEPTIONIST)));
    }

    public function test_view_denies_cross_tenant_access(): void
    {
        $owner = $this->user(UserRole::OWNER, 'tenant-a');

        $this->assertTrue($this->policy->view($owner, $this->appointment('tenant-a')));
        $this->assertFalse($this->policy->view($owner, $this->appointment('tenant-b')));
    }

    public function test_create_allows_owner_and_receptionist_only(): void
    {
        $this->assertTrue($this->policy->create($this->user(UserRole::OWNER)));
        $this->assertTrue($this->policy->create($this->user(UserRole::RECEPTIONIST)));
        $this->assertFalse($this->policy->create($this->user(UserRole::DOCTOR)));
    }

    public function test_update_denies_cross_tenant_access(): void
    {
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');

        $this->assertTrue($this->policy->update($receptionist, $this->appointment('tenant-a')));
        $this->assertFalse($this->policy->update($receptionist, $this->appointment('tenant-b')));
    }

    public function test_update_denies_doctor_regardless_of_tenant(): void
    {
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a');

        $this->assertFalse($this->policy->update($doctor, $this->appointment('tenant-a')));
    }

    public function test_update_status_allows_owner_and_receptionist_same_tenant(): void
    {
        $owner = $this->user(UserRole::OWNER, 'tenant-a');

        $this->assertTrue($this->policy->updateStatus(
            $owner,
            $this->appointment('tenant-a', status: AppointmentStatus::CHECKED_IN),
            AppointmentStatus::COMPLETED
        ));
    }

    public function test_update_status_denies_owner_cross_tenant(): void
    {
        $owner = $this->user(UserRole::OWNER, 'tenant-a');

        $this->assertFalse($this->policy->updateStatus($owner, $this->appointment('tenant-b'), AppointmentStatus::COMPLETED));
    }

    public function test_update_status_allows_the_treating_doctor_only(): void
    {
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a', 'doctor-1');
        $otherDoctor = $this->user(UserRole::DOCTOR, 'tenant-a', 'doctor-2');

        $appointment = $this->appointment('tenant-a', 'doctor-1', AppointmentStatus::CHECKED_IN);

        $this->assertTrue($this->policy->updateStatus($doctor, $appointment, AppointmentStatus::COMPLETED));
        $this->assertFalse($this->policy->updateStatus($otherDoctor, $appointment, AppointmentStatus::COMPLETED));
    }

    public function test_update_status_denies_treating_doctor_cross_tenant(): void
    {
        $doctor = $this->user(UserRole::DOCTOR, 'tenant-a', 'doctor-1');
        $appointment = $this->appointment('tenant-b', 'doctor-1');

        $this->assertFalse($this->policy->updateStatus($doctor, $appointment, AppointmentStatus::COMPLETED));
    }

    public function test_delete_is_owner_only_same_tenant(): void
    {
        $owner = $this->user(UserRole::OWNER, 'tenant-a');
        $receptionist = $this->user(UserRole::RECEPTIONIST, 'tenant-a');

        $this->assertTrue($this->policy->delete($owner, $this->appointment('tenant-a')));
        $this->assertFalse($this->policy->delete($owner, $this->appointment('tenant-b')));
        $this->assertFalse($this->policy->delete($receptionist, $this->appointment('tenant-a')));
    }
}
