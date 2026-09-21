<?php

namespace App\Http\Resources\V1;

use App\Models\PatientTreatmentVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientTreatmentVisit */
class PatientTreatmentVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_treatment_id' => $this->patient_treatment_id,
            'visit_order' => $this->visit_order,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'completed_at' => $this->completed_at?->toISOString(),
            'components' => PatientTreatmentComponentResource::collection($this->whenLoaded('components')),
            'appointments' => AppointmentResource::collection($this->whenLoaded('appointments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
