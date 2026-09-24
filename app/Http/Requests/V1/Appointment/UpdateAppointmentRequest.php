<?php

namespace App\Http\Requests\V1\Appointment;

use App\Enums\AppointmentType;
use App\Enums\UserRole;
use App\Rules\AppointmentDoctorAvailable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;

class UpdateAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => [
                'sometimes',
                Rule::exists('patients', 'id')->where(function ($query) {
                    $query->where('tenant_id', $this->user()->tenant_id);
                }),
            ],
            'doctor_id' => [
                'sometimes',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('role', UserRole::DOCTOR)
                        ->where('tenant_id', $this->user()->tenant_id);
                }),
            ],
            'patient_treatment_ids' => ['sometimes', 'array'],
            'patient_treatment_ids.*' => [
                'uuid',
                Rule::exists('patient_treatments', 'id')->where(function ($query) {
                    $query->where('tenant_id', $this->user()->tenant_id);
                }),
            ],
            'scheduled_at' => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1'],
            'appointment_type' => ['sometimes', Rule::enum(AppointmentType::class)],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if (! $this->filled('scheduled_at')) {
                return;
            }

            $appointment = $this->route('appointment');
            $newScheduledAt = Carbon::parse($this->input('scheduled_at'));
            $isActuallyChanging = ! $appointment->scheduled_at->equalTo($newScheduledAt);

            if ($isActuallyChanging && $newScheduledAt->isPast()) {
                $validator->errors()->add('scheduled_at', 'The scheduled time must be in the future.');
            }

            if ($validator->errors()->has('scheduled_at') || $validator->errors()->has('doctor_id') || $validator->errors()->has('duration_minutes')) {
                return;
            }

            $doctorId = $this->input('doctor_id', $appointment->doctor_id);
            $duration = (int) $this->input('duration_minutes', $appointment->duration_minutes);

            $rule = new AppointmentDoctorAvailable(
                tenantId: $this->user()->tenant_id,
                doctorId: $doctorId,
                scheduledAt: $newScheduledAt,
                durationMinutes: $duration,
                ignoreAppointmentId: $appointment->id,
            );

            $rule->validate('doctor_id', null, function (string $message, ?string $translate = null) use ($validator): PotentiallyTranslatedString {
                $validator->errors()->add('doctor_id', $message);

                return new PotentiallyTranslatedString($message, app('translator'));
            });
        });
    }
}
