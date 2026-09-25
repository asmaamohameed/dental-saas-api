<?php

namespace App\Http\Requests\V1\Clinic;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClinicLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'logo' => ['required', 'file', 'mimes:jpeg,jpg,png,webp,svg', 'max:2048'],
        ];
    }
}
