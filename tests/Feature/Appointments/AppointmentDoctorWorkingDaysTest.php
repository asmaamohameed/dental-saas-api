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
use Tests\TestCase;

class AppointmentDoctorWorkingDaysTest extends TestCase
{
    private string $clinicTz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clinicTz = (string) config('clinic.timezone', 'Africa/Cairo');
    }

    private function cairoInstant(string $localDateTime): string
    {
        return Carbon::parse($localDateTime, $this->clinicTz)->utc()->toIso8601String();
    }

    private function nextWeekdayDateInCairo(string $weekday): string
    {
        $cursor = Carbon::now($this->clinicTz)->addDay()->startOfDay();
        for ($i = 0; $i < 14; $i++) {
            if (strtolower($cursor->englishDayOfWeek) === strtolower($weekday)) {
                return $cursor->format('Y-m-d');
            }
            $cursor->addDay();
        }

        $this->fail('Could not find weekday '.$weekday);
    }

    /**
     * @return array<string, array{is_open: bool, open: string, close: string}>
     */
    private function scheduleWithLateClose(string $close = '23:59'): array
    {
        $schedule = [];
        foreach (WorkingDay::values() as $day) {
            $schedule[$day] = [
                'is_open' => true,
                'open' => '00:00',
                'close' => $close,
            ];
        }

        return $schedule;
    }

    public function test_cannot_book_on_day_outside_doctor_working_days(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create([
            'working_days' => ['saturday', 'monday'],
        ]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $sunday = Carbon::now($this->clinicTz)->next(Carbon::SUNDAY)->setTime(10, 0);

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

    public function test_can_book_on_day_inside_doctor_working_days(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create([
            'working_days' => ['saturday', 'monday'],
        ]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $monday = Carbon::now($this->clinicTz)->next(Carbon::MONDAY)->setTime(10, 0);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $monday->utc()->toIso8601String(),
            'duration_minutes' => 30,
        ])->assertCreated();
    }

    public function test_cannot_update_scheduled_at_to_a_non_working_day(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create([
            'working_days' => ['saturday', 'monday'],
        ]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $monday = Carbon::now($this->clinicTz)->next(Carbon::MONDAY)->setTime(10, 0);
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $monday->utc(),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $sunday = Carbon::now($this->clinicTz)->next(Carbon::SUNDAY)->setTime(10, 0);

        $this->putJson("/api/v1/appointments/{$appointment->id}", [
            'scheduled_at' => $sunday->utc()->toIso8601String(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_update_notes_only_does_not_revalidate_doctor_working_days(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create([
            'working_days' => ['monday'],
        ]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $monday = Carbon::now($this->clinicTz)->next(Carbon::MONDAY)->setTime(10, 0);
        $appointment = Appointment::factory()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $monday->utc(),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $doctor->update(['working_days' => ['tuesday']]);

        $this->putJson("/api/v1/appointments/{$appointment->id}", [
            'notes' => 'Follow-up instructions updated',
        ])->assertOk();
    }

    public function test_doctor_with_null_working_days_skips_working_day_check(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create(['working_days' => null]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $sunday = Carbon::now($this->clinicTz)->next(Carbon::SUNDAY)->setTime(10, 0);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $sunday->utc()->toIso8601String(),
            'duration_minutes' => 30,
        ])->assertCreated();
    }

    public function test_doctor_with_empty_working_days_array_rejects_every_booking(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create([
            'working_days' => [],
        ]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $this->scheduledAtWithinClinicHours(),
            'duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_booking_at_2330_cairo_on_working_day_is_accepted(): void
    {
        $tenant = Tenant::factory()->create(['working_hours' => $this->scheduleWithLateClose()]);
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create([
            'working_days' => [WorkingDay::MONDAY],
        ]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $date = $this->nextWeekdayDateInCairo(WorkingDay::MONDAY);
        $scheduledAt = $this->cairoInstant("{$date} 23:30:00");

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 29,
        ])->assertCreated();
    }

    public function test_booking_at_2330_cairo_on_non_working_day_is_rejected(): void
    {
        $tenant = Tenant::factory()->create(['working_hours' => $this->scheduleWithLateClose()]);
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();
        $doctor = User::factory()->doctor()->create([
            'working_days' => ['saturday', 'monday'],
        ]);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $date = $this->nextWeekdayDateInCairo(WorkingDay::SUNDAY);
        $scheduledAt = $this->cairoInstant("{$date} 23:30:00");

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }

    public function test_working_day_weekday_uses_clinic_timezone_not_utc(): void
    {
        $tenant = Tenant::factory()->create(['working_hours' => $this->scheduleWithLateClose()]);
        app(CurrentTenant::class)->set($tenant->id);
        $patient = Patient::factory()->create();

        $tuesdayDate = $this->nextWeekdayDateInCairo(WorkingDay::TUESDAY);
        $scheduledAt = $this->cairoInstant("{$tuesdayDate} 01:30:00");

        $cairoWeekday = strtolower(Carbon::parse($scheduledAt)->timezone($this->clinicTz)->englishDayOfWeek);
        $utcWeekday = strtolower(Carbon::parse($scheduledAt)->utc()->englishDayOfWeek);
        $this->assertSame(WorkingDay::TUESDAY, $cairoWeekday);
        $this->assertNotSame($cairoWeekday, $utcWeekday);

        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $doctorWorksTuesday = User::factory()->doctor()->create([
            'working_days' => [WorkingDay::TUESDAY],
        ]);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctorWorksTuesday->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
        ])->assertCreated();

        $doctorWorksMondayOnly = User::factory()->doctor()->create([
            'working_days' => [WorkingDay::MONDAY],
        ]);

        $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctorWorksMondayOnly->id,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => 30,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scheduled_at');
    }
}
