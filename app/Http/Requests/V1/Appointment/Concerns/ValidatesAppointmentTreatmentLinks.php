<?php

namespace App\Http\Requests\V1\Appointment\Concerns;

use App\Enums\AppointmentType;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesAppointmentTreatmentLinks
{
    protected function validateTreatmentLinksForType(Validator $validator): void
    {
        $treatmentIds = $this->input('patient_treatment_ids', []);
        if ($treatmentIds === []) {
            return;
        }

        $typeValue = $this->input('appointment_type');
        if ($typeValue === null && $this->route('appointment')) {
            $typeValue = $this->route('appointment')->appointment_type->value;
        }
        $typeValue ??= AppointmentType::CONSULTATION->value;

        if ($typeValue !== AppointmentType::TREATMENT_VISIT->value) {
            $validator->errors()->add(
                'patient_treatment_ids',
                'Open treatments can only be linked to treatment appointments.'
            );
        }
    }
}
