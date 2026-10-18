<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use App\Support\WorkingDay;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentClinicHoursTest extends TestCase
{
    private string $clinicTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinicTz = (string) config('clinic.timezone');
    }

    /**
     * @return array<string, array{is_open: bool, open: string, close: string}>
     */
    private function standardWeek(): array
    {
        $schedule = [];
        foreach (WorkingDay::values() as $day) {
            $schedule[$day] = [
                'is_open' => true,
                'open' => '09:00',
                'close' => '21:00',
            ];
        }

        return $schedule;
    }

    private function cairoInstant(string $localDateTime): string
    {
        return Carbon::parse($localDateTime, $this->clinicTz)->utc()->toIso8601String();
    }

    private function nextWeekdayInCairo(string $weekday): string
    {
        $cursor = Carbon::now($this->clinicTz)->addDay()->startOfDay();
        for ($i = 0; $i < 14; $i++) {
            if (strtolower($cursor->englishDayOfWeek) === $weekday) {
                return $cursor->format('Y-m-d');
            }
            $cursor->addDay();
        }

        $this->fail('Could not find weekday '.$weekday);
    }

    public function test_booking_at_20_00_cairo_accepted_when_close_is_21_00_via_iso_utc(): void
    {
        $tenant = Tenant::factory()->create(['working_hours' => $this->standardWeek()]);
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($user, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $tenant->id]);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $tenant->id]);

        $date = $this->nextWeekdayInCairo(WorkingDay::MONDAY);
        $scheduledAt = $this->cairoInstant("{$date} 20:00:00");

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
        ])->assertCreated();
    }

    public function test_booking_at_21_30_cairo_rejected(): void
    {
        $tenant = Tenant::factory()->create(['working_hours' => $this->standardWeek()]);
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($user, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $tenant->id]);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $tenant->id]);

        $date = $this->nextWeekdayInCairo(WorkingDay::MONDAY);
        $scheduledAt = $this->cairoInstant("{$date} 21:30:00");

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_booking_ending_exactly_at_close_is_allowed(): void
    {
        $tenant = Tenant::factory()->create(['working_hours' => $this->standardWeek()]);
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($user, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $tenant->id]);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $tenant->id]);

        $date = $this->nextWeekdayInCairo(WorkingDay::TUESDAY);
        $scheduledAt = $this->cairoInstant("{$date} 20:30:00");

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
        ])->assertCreated();
    }

    public function test_booking_on_closed_day_rejected(): void
    {
        $schedule = $this->standardWeek();
        $schedule[WorkingDay::FRIDAY]['is_open'] = false;

        $tenant = Tenant::factory()->create(['working_hours' => $schedule]);
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($user, ['*']);

        $patient = Patient::factory()->create(['tenant_id' => $tenant->id]);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $tenant->id]);

        $date = $this->nextWeekdayInCairo(WorkingDay::FRIDAY);
        $scheduledAt = $this->cairoInstant("{$date} 10:00:00");

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_reschedule_into_closed_time_rejected(): void
    {
        $schedule = $this->standardWeek();
        $schedule[WorkingDay::WEDNESDAY]['close'] = '17:00';

        $tenant = Tenant::factory()->create(['working_hours' => $schedule]);
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($user, ['*']);

        $appointment = Appointment::factory()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
            'scheduled_at' => Carbon::parse($this->cairoInstant($this->nextWeekdayInCairo(WorkingDay::MONDAY).' 10:00:00')),
        ]);

        $date = $this->nextWeekdayInCairo(WorkingDay::WEDNESDAY);
        $newTime = $this->cairoInstant("{$date} 16:45:00");

        $this->putJson("/api/v1/appointments/{$appointment->id}", [
            'scheduled_at' => $newTime,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    public function test_doctor_only_change_allowed_for_legacy_outside_hours_appointment(): void
    {
        $tenant = Tenant::factory()->create(['working_hours' => $this->standardWeek()]);
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($user, ['*']);

        $doctorA = User::factory()->doctor()->create(['tenant_id' => $tenant->id]);
        $doctorB = User::factory()->doctor()->create(['tenant_id' => $tenant->id]);

        $date = $this->nextWeekdayInCairo(WorkingDay::MONDAY);
        $legacyTime = $this->cairoInstant("{$date} 22:00:00");

        $appointment = Appointment::factory()->create([
            'tenant_id' => $tenant->id,
            'doctor_id' => $doctorA->id,
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
            'scheduled_at' => Carbon::parse($legacyTime),
        ]);

        $this->putJson("/api/v1/appointments/{$appointment->id}", [
            'doctor_id' => $doctorB->id,
        ])->assertOk();

        $this->assertSame($doctorB->id, $appointment->fresh()->doctor_id);
    }

    public function test_notes_only_update_skips_clinic_hours(): void
    {
        $tenant = Tenant::factory()->create(['working_hours' => $this->standardWeek()]);
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($user, ['*']);

        $date = $this->nextWeekdayInCairo(WorkingDay::MONDAY);
        $legacyTime = $this->cairoInstant("{$date} 22:00:00");

        $appointment = Appointment::factory()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
            'scheduled_at' => Carbon::parse($legacyTime),
            'notes' => 'Old',
        ]);

        $this->putJson("/api/v1/appointments/{$appointment->id}", [
            'notes' => 'Updated note',
        ])->assertOk();

        $this->assertSame('Updated note', $appointment->fresh()->notes);
    }

    public function test_status_transition_works_for_legacy_outside_hours_appointment(): void
    {
        $tenant = Tenant::factory()->create(['working_hours' => $this->standardWeek()]);
        app(CurrentTenant::class)->set($tenant->id);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::RECEPTIONIST,
        ]);
        Sanctum::actingAs($user, ['*']);

        $date = $this->nextWeekdayInCairo(WorkingDay::MONDAY);
        $legacyTime = $this->cairoInstant("{$date} 22:00:00");

        $appointment = Appointment::factory()->create([
            'tenant_id' => $tenant->id,
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
            'scheduled_at' => Carbon::parse($legacyTime),
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => AppointmentStatus::CHECKED_IN->value,
        ])->assertOk();

        $this->assertSame(AppointmentStatus::CHECKED_IN, $appointment->fresh()->status);
    }
}
