<?php

namespace App\Http\Requests\V1\Clinic;

use App\Enums\ClinicTheme;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClinicThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'theme' => ['required', 'string', Rule::enum(ClinicTheme::class)],
        ];
    }
}
