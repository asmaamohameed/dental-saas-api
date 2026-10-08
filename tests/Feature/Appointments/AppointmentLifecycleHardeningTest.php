<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Events\PatientCheckedIn;
use App\Models\Appointment;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AppointmentLifecycleHardeningTest extends TestCase
{
    public function test_broadcast_failure_still_persists_checked_in_status(): void
    {
        Event::listen(PatientCheckedIn::class, function (): void {
            throw new \RuntimeException('Broadcast failed');
        });

        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $appointment = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk()
            ->assertJsonPath('data.status', 'checked_in');

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::CHECKED_IN, $appointment->status);
        $this->assertNotNull($appointment->checked_in_at);
    }

    public function test_unauthorized_invalid_transition_returns_403_authorized_returns_422(): void
    {
        $owner = $this->actingAsTenantUser(role: UserRole::OWNER);
        $appointment = Appointment::factory()->create([
            'doctor_id' => $owner->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'completed',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['status']);

        $assistant = User::factory()->assistant()->create();
        Sanctum::actingAs($assistant, ['*']);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'completed',
        ])->assertForbidden();

        $appointment->refresh();
        $this->assertSame(AppointmentStatus::SCHEDULED, $appointment->status);
    }

    public function test_lifecycle_timestamps_set_on_transition_and_not_overwritten_on_repeat(): void
    {
        Carbon::setTestNow('2026-10-01 09:00:00 UTC');

        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $appointment = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        $appointment->refresh();
        $firstCheckedIn = $appointment->checked_in_at;
        $this->assertSame('2026-10-01 09:00:00', $firstCheckedIn->utc()->toDateTimeString());

        Carbon::setTestNow('2026-10-01 10:00:00 UTC');

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk();

        $appointment->refresh();
        $this->assertSame(
            $firstCheckedIn->toIso8601String(),
            $appointment->checked_in_at->toIso8601String()
        );

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'in_progress',
        ])->assertOk();

        $appointment->refresh();
        $this->assertSame('2026-10-01 10:00:00', $appointment->started_at->utc()->toDateTimeString());

        Carbon::setTestNow('2026-10-01 11:00:00 UTC');

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'completed',
        ])->assertOk();

        $appointment->refresh();
        $this->assertSame('2026-10-01 11:00:00', $appointment->completed_at->utc()->toDateTimeString());

        Carbon::setTestNow();
    }

    public function test_booked_doctor_id_set_once_when_doctor_changes_on_status_update(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $replacement = User::factory()->doctor()->create();
        $appointment = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
            'doctor_id' => $replacement->id,
        ])->assertOk();

        $appointment->refresh();
        $this->assertSame($doctor->id, $appointment->booked_doctor_id);
        $this->assertSame($replacement->id, $appointment->doctor_id);

        $another = User::factory()->doctor()->create();

        Sanctum::actingAs($replacement, ['*']);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'in_progress',
            'doctor_id' => $another->id,
        ])->assertOk();

        $appointment->refresh();
        $this->assertSame($doctor->id, $appointment->booked_doctor_id);
        $this->assertSame($another->id, $appointment->doctor_id);
    }

    public function test_appointment_resource_exposes_lifecycle_fields(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $appointment = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'checked_in_at',
                    'started_at',
                    'completed_at',
                    'booked_doctor_id',
                ],
            ])
            ->assertJsonPath('data.started_at', null)
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.booked_doctor_id', null);

        $this->assertNotNull($this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->json('data.checked_in_at'));
    }

    public function test_appointments_index_default_order_unchanged_and_sort_by_checked_in_at(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);

        $older = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->subDay(),
            'status' => AppointmentStatus::SCHEDULED,
        ]);
        $newer = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => now(),
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $ids = collect($this->getJson('/api/v1/appointments')->assertOk()->json('data.items'))
            ->pluck('id')
            ->all();

        $this->assertSame([$newer->id, $older->id], array_slice($ids, 0, 2));

        $firstCheckedIn = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->subHours(2),
            'status' => AppointmentStatus::CHECKED_IN,
            'checked_in_at' => now()->subHour(),
        ]);
        $secondCheckedIn = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->subHours(3),
            'status' => AppointmentStatus::CHECKED_IN,
            'checked_in_at' => now(),
        ]);
        Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => now()->subHours(4),
            'status' => AppointmentStatus::SCHEDULED,
            'checked_in_at' => null,
        ]);

        $sortedIds = collect($this->getJson('/api/v1/appointments?sort=checked_in_at')->assertOk()->json('data.items'))
            ->pluck('id')
            ->all();

        $firstIndex = array_search($firstCheckedIn->id, $sortedIds, true);
        $secondIndex = array_search($secondCheckedIn->id, $sortedIds, true);
        $this->assertNotFalse($firstIndex);
        $this->assertNotFalse($secondIndex);
        $this->assertLessThan($secondIndex, $firstIndex);
    }

    public function test_dashboard_patients_includes_checked_in_and_in_progress_counts(): void
    {
        $doctor = $this->actingAsTenantUser(role: UserRole::DOCTOR);
        $base = now()->startOfMonth()->addDay();

        Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => $base->copy()->addHours(0),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED,
        ]);
        Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => $base->copy()->addHours(2),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::CHECKED_IN,
        ]);
        Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => $base->copy()->addHours(4),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::IN_PROGRESS,
        ]);
        Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'scheduled_at' => $base->copy()->addHours(6),
            'duration_minutes' => 30,
            'status' => AppointmentStatus::COMPLETED,
        ]);

        $this->getJson('/api/v1/dashboard/patients')
            ->assertOk()
            ->assertJsonPath('data.scheduled', 1)
            ->assertJsonPath('data.checked_in', 1)
            ->assertJsonPath('data.in_progress', 1)
            ->assertJsonPath('data.completed', 1);

        $counts = app(DashboardService::class)->getPatients('this_month');
        $this->assertSame(1, $counts['scheduled']);
        $this->assertSame(1, $counts['checked_in']);
        $this->assertSame(1, $counts['in_progress']);
        $this->assertSame(1, $counts['completed']);
    }

    public function test_assistant_can_check_in_but_cannot_start_or_complete(): void
    {
        $assistant = $this->actingAsTenantUser(role: UserRole::ASSISTANT);
        $doctor = User::factory()->doctor()->create(['tenant_id' => $assistant->tenant_id]);

        $appointment = Appointment::factory()->create([
            'doctor_id' => $doctor->id,
            'status' => AppointmentStatus::SCHEDULED,
        ]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'checked_in',
        ])->assertOk()
            ->assertJsonPath('data.status', 'checked_in');

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'in_progress',
        ])->assertForbidden();

        $appointment->update(['status' => AppointmentStatus::IN_PROGRESS]);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/status", [
            'status' => 'completed',
        ])->assertForbidden();
    }
}
