<?php

namespace App\Http\Requests\V1\LabWork;

use App\Enums\LabWorkStatus;
use App\Support\LabWorks\LabFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabWorkStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('labWork'));
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(LabWorkStatus::class)],
            'received_on' => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return LabFields::work();
    }
}
