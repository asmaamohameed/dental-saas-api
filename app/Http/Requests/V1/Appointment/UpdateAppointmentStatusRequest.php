<?php

namespace App\Http\Requests\V1\Appointment;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Rules\AppointmentDoctorAvailable;
use App\Rules\AppointmentPatientAvailable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;

class UpdateAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            'doctor_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(function ($query) {
                    User::constrainUsersWithClinicRole($query, (string) $this->user()->tenant_id, UserRole::DOCTOR->value);
                }),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $newStatus = AppointmentStatus::from($this->input('status'));

            if ($newStatus === AppointmentStatus::CANCELLED) {
                return;
            }

            if (! $this->filled('doctor_id')) {
                return;
            }

            $appointment = $this->route('appointment');
            $newDoctorId = $this->input('doctor_id');

            if ($newDoctorId === $appointment->doctor_id) {
                return;
            }

            if ($validator->errors()->has('doctor_id')) {
                return;
            }

            $scheduledAt = $appointment->scheduled_at instanceof Carbon
                ? $appointment->scheduled_at->copy()
                : Carbon::parse($appointment->scheduled_at);

            $doctorRule = new AppointmentDoctorAvailable(
                tenantId: $this->user()->tenant_id,
                doctorId: $newDoctorId,
                scheduledAt: $scheduledAt,
                durationMinutes: (int) $appointment->duration_minutes,
                ignoreAppointmentId: $appointment->id,
            );

            if ($doctorConflictMessage = $doctorRule->conflictMessage()) {
                $validator->errors()->add('doctor_id', $doctorConflictMessage);

                return;
            }

            $patientRule = new AppointmentPatientAvailable(
                tenantId: $this->user()->tenant_id,
                patientId: $appointment->patient_id,
                scheduledAt: $scheduledAt,
                durationMinutes: (int) $appointment->duration_minutes,
                ignoreAppointmentId: $appointment->id,
            );

            $patientRule->validate('patient_id', null, function (string $message, ?string $translate = null) use ($validator): PotentiallyTranslatedString {
                $validator->errors()->add('patient_id', $message);

                return new PotentiallyTranslatedString($message, app('translator'));
            });
        });
    }
}
