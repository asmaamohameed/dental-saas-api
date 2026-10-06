<?php

namespace Tests\Feature\Staff;

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

class DoctorAvailabilityTest extends TestCase
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

    public function test_unauthenticated_user_gets_401(): void
    {
        $this->getJson('/api/v1/doctors/availability')->assertUnauthorized();
    }

    public function test_clinic_staff_can_read_availability(): void
    {
        User::factory()->doctor()->for($this->tenant)->create(['name' => 'Dr. A']);

        foreach ([UserRole::RECEPTIONIST, UserRole::ASSISTANT, UserRole::DOCTOR, UserRole::OWNER] as $role) {
            $user = User::factory()->{$role->value}()->for($this->tenant)->create();
            Sanctum::actingAs($user, ['*']);

            $this->getJson('/api/v1/doctors/availability')->assertOk();
        }
    }

    public function test_free_busy_waiting_in_visit_and_next_booking_within_window(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00 UTC');

        $busyDoctor = User::factory()->doctor()->for($this->tenant)->create([
            'name' => 'Busy',
            'working_days' => ['tuesday'],
        ]);
        $freeDoctor = User::factory()->doctor()->for($this->tenant)->create([
            'name' => 'Free',
            'working_days' => ['monday'],
        ]);

        $todayMorning = Carbon::parse('2026-10-06 09:00:00');
        $todayLater = Carbon::parse('2026-10-06 14:00:00');

        Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $busyDoctor->id,
            'scheduled_at' => $todayMorning,
            'status' => AppointmentStatus::CHECKED_IN,
        ]);

        Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => Patient::factory()->for($this->tenant)->create()->id,
            'doctor_id' => $freeDoctor->id,
            'scheduled_at' => $todayLater,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $receptionist = User::factory()->receptionist()->for($this->tenant)->create();
        Sanctum::actingAs($receptionist, ['*']);

        $response = $this->getJson('/api/v1/doctors/availability?date_from=2026-10-06T00:00:00Z&date_to=2026-10-06T23:59:59Z')
            ->assertOk();

        $byName = collect($response->json('data'))->keyBy('name');

        $this->assertSame('busy', $byName['Busy']['state']);
        $this->assertSame(1, $byName['Busy']['waiting_count']);
        $this->assertFalse($byName['Busy']['in_visit']);
        $this->assertTrue($byName['Busy']['works_today']);

        $this->assertSame('free', $byName['Free']['state']);
        $this->assertSame(0, $byName['Free']['waiting_count']);
        $this->assertFalse($byName['Free']['in_visit']);
        $this->assertFalse($byName['Free']['works_today']);
        $this->assertSame($todayLater->toIso8601String(), $byName['Free']['next_booking_at']);

        Carbon::setTestNow();
    }

    public function test_stale_in_progress_from_previous_day_does_not_make_doctor_busy_today(): void
    {
        Carbon::setTestNow('2026-10-06 10:00:00 UTC');

        $doctor = User::factory()->doctor()->for($this->tenant)->create(['name' => 'Dr. Stale']);

        Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => Carbon::parse('2026-10-05 15:00:00'),
            'status' => AppointmentStatus::IN_PROGRESS,
        ]);

        $receptionist = User::factory()->receptionist()->for($this->tenant)->create();
        Sanctum::actingAs($receptionist, ['*']);

        $payload = $this->getJson('/api/v1/doctors/availability?date_from=2026-10-06T00:00:00Z&date_to=2026-10-06T23:59:59Z')
            ->assertOk()
            ->json('data.0');

        $this->assertSame('free', $payload['state']);
        $this->assertFalse($payload['in_visit']);

        Carbon::setTestNow();
    }

    public function test_inactive_and_other_tenant_doctors_are_excluded(): void
    {
        User::factory()->doctor()->for($this->tenant)->create(['is_active' => false]);
        User::factory()->doctor()->for($this->tenant)->create(['is_active' => true, 'name' => 'Active']);

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        User::factory()->doctor()->for($otherTenant)->create();
        app(CurrentTenant::class)->set($this->tenant->id);

        $receptionist = User::factory()->receptionist()->for($this->tenant)->create();
        Sanctum::actingAs($receptionist, ['*']);

        $this->getJson('/api/v1/doctors/availability')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Active');
    }
}
