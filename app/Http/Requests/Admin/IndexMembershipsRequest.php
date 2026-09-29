<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class IndexMembershipsRequest extends AdminFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'clinic_id' => ['sometimes', 'nullable', 'uuid', 'exists:tenants,id'],
            'user_id' => ['sometimes', 'nullable', 'uuid', 'exists:users,id'],
            'role' => ['sometimes', 'nullable', Rule::enum(UserRole::class)],
            'is_active' => ['sometimes', 'nullable', Rule::in([true, false, 0, 1, '0', '1', 'true', 'false'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
