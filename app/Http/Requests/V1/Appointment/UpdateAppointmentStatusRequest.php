<?php

namespace App\Http\Requests\V1\Appointment;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAppointmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('appointment.updateStatus');
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(AppointmentStatus::class)],
            'doctor_id' => [
                'nullable',
                Rule::exists('users', 'id')->where(function ($query) {
                    $query->where('role', UserRole::DOCTOR)
                        ->where('tenant_id', $this->user()->tenant_id);
                }),
            ],
        ];
    }
}
