<?php

namespace App\Http\Resources\V1;

use App\Models\PatientTreatment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientTreatment */
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
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient?->id,
                'full_name' => $this->patient?->full_name,
                'phone' => $this->patient?->phone,
            ]),
            'template' => new TreatmentTemplateResource($this->whenLoaded('template')),
            'doctor' => $this->whenLoaded('doctor', fn () => [
                'id' => $this->doctor?->id,
                'name' => $this->doctor?->name,
            ]),
            'visits' => PatientTreatmentVisitResource::collection($this->whenLoaded('visits')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
