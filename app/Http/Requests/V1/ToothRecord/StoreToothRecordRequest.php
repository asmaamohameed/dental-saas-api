<?php

namespace App\Http\Requests\V1\ToothRecord;

use App\Enums\ToothTreatmentStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreToothRecordRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'appointment_id' => ['nullable', 'string', 'exists:appointments,id'],
            'tooth_number' => ['required', 'string', 'regex:/^[1-4][1-8]$/'],
            'condition' => ['required', 'string', 'in:healthy,decayed,filled,missing,crown,root_canal,needs_extraction,impacted'],
            'treatment_status' => ['required', 'string', Rule::enum(ToothTreatmentStatus::class)],
            'notes' => ['nullable', 'string'],
        ];
    }
}
