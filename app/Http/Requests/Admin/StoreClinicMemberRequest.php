<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class StoreClinicMemberRequest extends AdminFormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(UserRole::class)],
            'user_id' => ['required_without:email', 'prohibits:name,email,password,phone', 'uuid', 'exists:users,id'],
            'name' => ['required_without:user_id', 'prohibits:user_id', 'string', 'max:255'],
            'email' => ['required_without:user_id', 'prohibits:user_id', 'email', 'max:255'],
            'password' => ['required_without:user_id', 'prohibits:user_id', 'string', 'min:8'],
            'phone' => ['nullable', 'prohibits:user_id', 'string', 'max:50'],
        ];
    }
}
