<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;

class IndexAuditLogsRequest extends AdminFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['sometimes', 'nullable', 'string', 'max:100'],
            'target_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'target_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tenant_id' => ['sometimes', 'nullable', 'uuid', 'exists:tenants,id'],
            'admin_user_id' => ['sometimes', 'nullable', 'uuid', 'exists:admin_users,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
