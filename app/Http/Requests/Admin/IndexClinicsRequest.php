<?php

namespace App\Http\Requests\Admin;

use App\Enums\TenantStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class IndexClinicsRequest extends AdminFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'nullable', Rule::enum(TenantStatus::class)],
            'issue' => ['sometimes', 'nullable', 'string', Rule::in([
                'no_members',
                'no_active_owner',
                'no_doctors',
                'inactive_memberships',
                'deactivated_owner',
                'duplicate_name',
                'suspended_with_active_members',
            ])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
