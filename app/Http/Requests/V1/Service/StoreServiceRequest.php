<?php

namespace App\Http\Requests\V1\Service;

use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => [
                'required',
                'string',
                'max:255',
                Rule::unique('services')->where(
                    fn ($query) => $query->where('tenant_id', app(CurrentTenant::class)->id())
                ),
            ],
            'name_en' => ['nullable', 'string', 'max:255'],
            'default_price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ];
    }
}
