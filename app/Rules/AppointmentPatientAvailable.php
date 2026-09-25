<?php

namespace App\Rules;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;

class AppointmentPatientAvailable implements ValidationRule
{
    public function __construct(
        protected string $tenantId,
        protected string $patientId,
        protected Carbon $scheduledAt,
        protected int $durationMinutes,
        protected ?string $ignoreAppointmentId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $newStart = $this->scheduledAt;
        $newEnd = $this->scheduledAt->copy()->addMinutes($this->durationMinutes);

        $exists = Appointment::query()
            ->where('tenant_id', $this->tenantId)
            ->where('patient_id', $this->patientId)
            ->where('status', '!=', AppointmentStatus::CANCELLED)
            ->when($this->ignoreAppointmentId, fn ($q) => $q->where('id', '!=', $this->ignoreAppointmentId))
            ->whereRaw(
                'scheduled_at < ? AND (scheduled_at + (duration_minutes * interval \'1 minute\')) > ?',
                [$newEnd, $newStart]
            )
            ->exists();

        if ($exists) {
            $fail('This patient already has an appointment during this time slot.');
        }
    }
}
