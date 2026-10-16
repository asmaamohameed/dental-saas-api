<?php

namespace App\Http\Requests\V1\RolePermission;

use App\Support\Roles\ClinicPermissionCatalog;
use App\Support\Roles\ClinicPermissionGate;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClinicRolePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissions' => ['required', 'array'],
            'permissions.*' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $role = (string) $this->route('role');

            if (! in_array($role, ClinicPermissionGate::EDITABLE_ROLES, true)) {
                $validator->errors()->add('role', 'This role cannot be changed.');
            }

            $known = array_keys(ClinicPermissionCatalog::labels());

            foreach (array_keys((array) $this->input('permissions', [])) as $key) {
                if (! in_array($key, $known, true)) {
                    $validator->errors()->add('permissions', 'Unknown permission: '.$key);
                }
            }
        });
    }
}
