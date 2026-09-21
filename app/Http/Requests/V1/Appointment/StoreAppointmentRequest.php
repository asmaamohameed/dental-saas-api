<?php

namespace App\Http\Requests\V1\Appointment;

use App\Enums\UserRole;
use App\Rules\AppointmentDoctorAvailable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Rule;

class StoreAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'patient_id' => [
                'required',
                Rule::exists('patients', 'id')->where(function ($query) {
                    $query->where('tenant_id', $this->user()->tenant_id);
                }),
            ],
            'doctor_id' => [
                'required',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('role', UserRole::DOCTOR)
                        ->where('tenant_id', $this->user()->tenant_id);
                }),
            ],
            'scheduled_at' => ['required', 'date'],
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string'],
        ];
    }
   
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            if ($this->filled('scheduled_at') && Carbon::parse($this->input('scheduled_at'))->isPast()) {
                $validator->errors()->add('scheduled_at', 'The scheduled time must be in the future.');
            }

            if ($validator->errors()->has('doctor_id') || $validator->errors()->has('scheduled_at') || $validator->errors()->has('duration_minutes')) {
                return;
            }

            $rule = new AppointmentDoctorAvailable(
                tenantId: $this->user()->tenant_id,
                doctorId: $this->input('doctor_id'),
                scheduledAt: Carbon::parse($this->input('scheduled_at')),
                durationMinutes: (int) $this->input('duration_minutes'),
            );

            $rule->validate('doctor_id', null, function (string $message, ?string $translate = null) use ($validator): PotentiallyTranslatedString {
                $validator->errors()->add('doctor_id', $message);

                return new PotentiallyTranslatedString($message, app('translator'));
            });
        });
    }
}
