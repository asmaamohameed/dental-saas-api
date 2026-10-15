<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(
        private readonly ClinicWorkingHoursService $clinicWorkingHours,
    ) {}

    /**
     * Apply service-linked defaults (fee and duration snapshots) before persistence.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function applyServiceDefaults(array $data): array
    {
        if (! array_key_exists('service_id', $data) || $data['service_id'] === null) {
            return $data;
        }

        /** @var TreatmentTemplate $template */
        $template = TreatmentTemplate::query()->findOrFail($data['service_id']);

        if (! array_key_exists('fee', $data) || $data['fee'] === null) {
            $data['fee'] = $template->default_price;
        }

        if (! array_key_exists('duration_minutes', $data) || $data['duration_minutes'] === null) {
            $data['duration_minutes'] = $template->estimated_duration_minutes;
        }

        return $data;
    }

    public function assertWithinClinicHours(Carbon $scheduledAt, int $durationMinutes, ?Tenant $tenant = null): void
    {
        $tenant ??= Tenant::query()->findOrFail(app(CurrentTenant::class)->id());
        $schedule = $this->clinicWorkingHours->resolveForTenant($tenant);

        $message = $this->clinicWorkingHours->violationMessage($scheduledAt, $durationMinutes, $schedule);
        if ($message !== null) {
            throw ValidationException::withMessages([
                'scheduled_at' => [$message],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function shouldValidateClinicHoursOnUpdate(array $data, Appointment $appointment): bool
    {
        if (array_key_exists('scheduled_at', $data)) {
            $newAt = Carbon::parse($data['scheduled_at']);
            if (! $appointment->scheduled_at->equalTo($newAt)) {
                return true;
            }
        }

        if (array_key_exists('duration_minutes', $data)) {
            $newDuration = (int) $data['duration_minutes'];
            if ($newDuration !== (int) $appointment->duration_minutes) {
                return true;
            }
        }

        return false;
    }
}
