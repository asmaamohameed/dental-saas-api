<?php

namespace App\Http\Resources\V1;

use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin PatientTreatment
 *
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class PatientTreatmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'treatment_template_id' => $this->treatment_template_id,
            'doctor_id' => $this->doctor_id,
            'tooth_number' => $this->tooth_number,
            'diagnosis' => $this->diagnosis,
            'status' => $this->status,
            'priority' => $this->priority,
            'actual_price' => (float) $this->actual_price,
            'notes' => $this->notes,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'created_by' => $this->created_by,
            'patient' => $this->whenLoaded('patient', function () {
                /** @var Patient|null $patient */
                $patient = $this->patient;

                return [
                    'id' => $patient?->id,
                    'full_name' => $patient?->full_name,
                    'phone' => $patient?->phone,
                ];
            }),
            'template' => new TreatmentTemplateResource($this->whenLoaded('template')),
            'doctor' => $this->whenLoaded('doctor', function () {
                /** @var User|null $doctor */
                $doctor = $this->doctor;

                return [
                    'id' => $doctor?->id,
                    'name' => $doctor?->name,
                ];
            }),
            'visits' => PatientTreatmentVisitResource::collection($this->whenLoaded('visits')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
