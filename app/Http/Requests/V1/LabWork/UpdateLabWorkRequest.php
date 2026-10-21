<?php

namespace App\Http\Requests\V1\LabWork;

use App\Enums\LabWorkStatus;
use App\Models\LabWork;
use App\Support\LabWorks\LabFields;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLabWorkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('labWork'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('description')) {
            $this->merge([
                'description' => trim((string) $this->input('description')),
            ]);
        }
    }

    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'lab_id' => [
                'sometimes',
                'uuid',
                Rule::exists('labs', 'id')->where('tenant_id', $tenantId),
            ],
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sent_on' => ['sometimes', 'date'],
            'expected_on' => ['sometimes', 'date'],
            'received_on' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return LabFields::work();
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $work = $this->route('labWork');

            if (! $work instanceof LabWork) {
                return;
            }

            if ($work->status === LabWorkStatus::CANCELLED) {
                $validator->errors()->add('status', __('lab.cancelled_not_editable'));

                return;
            }

            $sent = (string) $this->input('sent_on', $work->sent_on->format('Y-m-d'));
            $expected = (string) $this->input('expected_on', $work->expected_on->format('Y-m-d'));

            if ($expected < $sent) {
                $validator->errors()->add('expected_on', __('lab.expected_before_sent'));
            }
        });
    }
}
