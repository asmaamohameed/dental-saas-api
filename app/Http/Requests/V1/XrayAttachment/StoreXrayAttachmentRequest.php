<?php

namespace App\Http\Requests\V1\XrayAttachment;

use App\Models\Appointment;
use App\Models\Patient;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreXrayAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png,application/pdf',
                'max:10240',
            ],
            'appointment_id' => [
                'nullable',
                'uuid',
                Rule::exists('appointments', 'id')->where('tenant_id', app(CurrentTenant::class)->id()),
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('appointment_id')) {
                return;
            }

            /** @var Patient|null $patient */
            $patient = $this->route('patient');
            if (! $patient) {
                return;
            }

            $appointment = Appointment::query()->find($this->input('appointment_id'));

            if ($appointment && (string) $appointment->patient_id !== (string) $patient->id) {
                $validator->errors()->add('appointment_id', 'The appointment does not belong to this patient.');
            }
        });
    }
}
