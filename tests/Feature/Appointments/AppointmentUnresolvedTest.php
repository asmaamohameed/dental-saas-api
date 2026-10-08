<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AppointmentUnresolvedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_returns_stale_checked_in_and_in_progress_before_today(): void
    {
        $this->actingAsTenantUser(role: UserRole::RECEPTIONIST);

        $staleCheckedIn = Appointment::factory()->checkedIn()->create([
            'scheduled_at' => Carbon::parse('2026-10-06 10:00:00'),
        ]);
        $staleInProgress = Appointment::factory()->inProgress()->create([
            'scheduled_at' => Carbon::parse('2026-10-05 14:00:00'),
        ]);

        Appointment::factory()->checkedIn()->create([
            'scheduled_at' => Carbon::parse('2026-10-07 09:00:00'),
        ]);
        Appointment::factory()->create([
            'scheduled_at' => Carbon::parse('2026-10-06 10:00:00'),
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $response = $this->getJson('/api/v1/appointments/unresolved')
            ->assertOk();

        $ids = collect($response->json('data.items'))->pluck('id');
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($staleCheckedIn->id));
        $this->assertTrue($ids->contains($staleInProgress->id));

        $first = $response->json('data.items.0');
        $this->assertArrayHasKey('patient_name', $first);
        $this->assertArrayHasKey('doctor_name', $first);
        $this->assertArrayHasKey('scheduled_at', $first);
        $this->assertArrayHasKey('status', $first);
    }

    public function test_excludes_today_scheduled_at_even_when_checked_in(): void
    {
        $this->actingAsTenantUser(role: UserRole::OWNER);

        Appointment::factory()->checkedIn()->create([
            'scheduled_at' => Carbon::parse('2026-10-07 08:30:00'),
        ]);

        $this->getJson('/api/v1/appointments/unresolved')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 0);
    }

    public function test_tenant_isolation(): void
    {
        $tenantA = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantA->id);
        $staleA = Appointment::factory()->checkedIn()->create([
            'scheduled_at' => Carbon::parse('2026-10-06 10:00:00'),
        ]);

        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        Appointment::factory()->checkedIn()->create([
            'scheduled_at' => Carbon::parse('2026-10-06 11:00:00'),
        ]);

        $this->actingAsTenantUser($tenantA, UserRole::RECEPTIONIST);

        $ids = collect(
            $this->getJson('/api/v1/appointments/unresolved')->assertOk()->json('data.items')
        )->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($staleA->id));
    }

    public function test_doctor_sees_only_own_unresolved_appointments(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $otherDoctor = User::factory()->doctor()->create(['tenant_id' => $doctor->tenant_id]);

        $mine = Appointment::factory()->checkedIn()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => Carbon::parse('2026-10-06 09:00:00'),
        ]);
        Appointment::factory()->checkedIn()->create([
            'doctor_id' => $otherDoctor->id,
            'scheduled_at' => Carbon::parse('2026-10-06 10:00:00'),
        ]);

        $ids = collect(
            $this->getJson('/api/v1/appointments/unresolved')->assertOk()->json('data.items')
        )->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($mine->id));
    }

    public function test_owner_sees_all_tenant_unresolved_appointments(): void
    {
        $owner = $this->actingAsTenantUser(role: UserRole::OWNER);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $owner->tenant_id]);

        Appointment::factory()->checkedIn()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => Carbon::parse('2026-10-06 09:00:00'),
        ]);
        Appointment::factory()->inProgress()->create([
            'doctor_id' => $owner->id,
            'scheduled_at' => Carbon::parse('2026-10-05 15:00:00'),
        ]);

        $this->getJson('/api/v1/appointments/unresolved')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 2);
    }

    public function test_assistant_can_access_unresolved_list(): void
    {
        $this->actingAsTenantUser(role: UserRole::ASSISTANT);

        Appointment::factory()->checkedIn()->create([
            'scheduled_at' => Carbon::parse('2026-10-06 10:00:00'),
        ]);

        $this->getJson('/api/v1/appointments/unresolved')
            ->assertOk()
            ->assertJsonPath('data.meta.total', 1);
    }
}
