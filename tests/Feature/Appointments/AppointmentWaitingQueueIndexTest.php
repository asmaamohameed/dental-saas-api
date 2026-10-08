<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AppointmentWaitingQueueIndexTest extends TestCase
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

    public function test_checked_in_index_filters_by_checked_in_at_not_scheduled_at(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);

        $todayWaiting = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::CHECKED_IN,
            'scheduled_at' => Carbon::parse('2026-10-06 10:00:00'),
            'checked_in_at' => Carbon::parse('2026-10-07 09:15:00'),
        ]);

        Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::CHECKED_IN,
            'scheduled_at' => Carbon::parse('2026-10-07 10:00:00'),
            'checked_in_at' => Carbon::parse('2026-10-07 09:30:00'),
        ]);

        Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::CHECKED_IN,
            'scheduled_at' => Carbon::parse('2026-10-06 10:00:00'),
            'checked_in_at' => Carbon::parse('2026-10-06 09:00:00'),
        ]);

        $from = Carbon::parse('2026-10-07 00:00:00')->toIso8601String();
        $to = Carbon::parse('2026-10-07 23:59:59')->toIso8601String();

        $ids = collect(
            $this->getJson('/api/v1/appointments?'.http_build_query([
                'doctor_id' => $doctor->id,
                'status' => 'checked_in',
                'checked_in_from' => $from,
                'checked_in_to' => $to,
                'per_page' => 100,
            ]))->assertOk()->json('data.items')
        )->pluck('id');

        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($todayWaiting->id));
    }

    public function test_in_progress_index_filters_by_started_at_window(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);

        $todayVisit = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::IN_PROGRESS,
            'scheduled_at' => Carbon::parse('2026-10-06 11:00:00'),
            'checked_in_at' => Carbon::parse('2026-10-07 08:00:00'),
            'started_at' => Carbon::parse('2026-10-07 09:00:00'),
        ]);

        Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::IN_PROGRESS,
            'scheduled_at' => Carbon::parse('2026-10-05 11:00:00'),
            'started_at' => Carbon::parse('2026-10-05 09:00:00'),
        ]);

        $from = Carbon::parse('2026-10-07 00:00:00')->toIso8601String();
        $to = Carbon::parse('2026-10-07 23:59:59')->toIso8601String();

        $ids = collect(
            $this->getJson('/api/v1/appointments?'.http_build_query([
                'doctor_id' => $doctor->id,
                'status' => 'in_progress',
                'started_from' => $from,
                'started_to' => $to,
                'per_page' => 100,
            ]))->assertOk()->json('data.items')
        )->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertTrue($ids->contains($todayVisit->id));
    }
}
