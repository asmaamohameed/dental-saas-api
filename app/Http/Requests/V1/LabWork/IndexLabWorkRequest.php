<?php

namespace App\Http\Requests\V1\LabWork;

use App\Enums\LabWorkListTab;
use App\Enums\LabWorkTimingFilter;
use App\Models\LabWork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexLabWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', LabWork::class);
    }

    public function rules(): array
    {
        return [
            'tab' => ['sometimes', Rule::enum(LabWorkListTab::class)],
            'timing' => ['sometimes', Rule::enum(LabWorkTimingFilter::class)],
            'lab_id' => ['sometimes', 'uuid'],
            'patient_id' => ['sometimes', 'uuid'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_from' => ['sometimes', 'date'],
            'date_to' => ['sometimes', 'date', 'after_or_equal:date_from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
