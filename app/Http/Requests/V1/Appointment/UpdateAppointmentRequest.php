<?php

namespace App\Http\Requests\V1\Appointment;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
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
            'scheduled_at' => ['sometimes', 'date'],
            'duration_minutes' => ['sometimes', 'integer', 'min:1'],
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
        });
    }
}
