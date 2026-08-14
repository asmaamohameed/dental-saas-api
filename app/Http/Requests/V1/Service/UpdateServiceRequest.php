<?php

namespace App\Http\Requests\V1\Service;

use App\Enums\UserRole;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('services')
                    ->where(fn ($query) => $query->where('tenant_id', app(CurrentTenant::class)->id()))
                    ->ignore($this->route('service')),
            ],
            'name_en' => ['nullable', 'string', 'max:255'],
            'default_price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    // UpdateServiceRequest.php
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->has('default_price') && $this->user()?->role !== UserRole::OWNER) {
                $validator->errors()->add('default_price', 'Only the clinic owner can edit the default price.');
            }
        });
    }
}
