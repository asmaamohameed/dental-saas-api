<?php

namespace App\Http\Requests\V1\Appointment;

use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
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
}
