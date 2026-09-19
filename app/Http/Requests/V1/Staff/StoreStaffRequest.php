<?php

namespace App\Http\Requests\V1\Staff;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
            'role' => ['required', Rule::in([UserRole::DOCTOR->value, UserRole::RECEPTIONIST->value])],
            'locale' => ['nullable', 'string', 'max:10'],
        ];
    }
}
