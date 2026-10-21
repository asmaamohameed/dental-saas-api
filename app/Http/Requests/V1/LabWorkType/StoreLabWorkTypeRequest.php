<?php

namespace App\Http\Requests\V1\LabWorkType;

use App\Models\LabWorkType;
use App\Support\LabWorks\LabFields;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreLabWorkTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', LabWorkType::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge([
                'name' => LabWorkType::displayName($this->input('name')),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $key = LabWorkType::nameKey((string) $value);

                    if (LabWorkType::query()->where('name_key', $key)->exists()) {
                        $fail(__('lab.work_already_listed'));

                        return;
                    }

                    if (LabWorkType::query()->count() >= LabWorkType::MAX_PER_CLINIC) {
                        $fail(__('lab.work_list_full'));
                    }
                },
            ],
        ];
    }

    public function attributes(): array
    {
        return LabFields::workName();
    }
}
