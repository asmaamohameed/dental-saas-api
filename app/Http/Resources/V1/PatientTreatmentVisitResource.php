<?php

namespace App\Http\Resources\V1;

use App\Models\PatientTreatmentVisit;
use App\Models\User;
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
            'scheduled_date' => $this->scheduled_date?->toISOString(),
            'completed_date' => $this->completed_date?->toISOString() ?: $this->completed_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'dentist_id' => $this->dentist_id,
            'appointment_id' => $this->appointment_id,
            'status_reason' => $this->status_reason,
            'visit_price' => $this->visit_price !== null ? (float) $this->visit_price : null,
            'dentist' => $this->whenLoaded('dentist', function () {
                /** @var User $dentist */
                $dentist = $this->dentist;

                return [
                    'id' => $dentist->id,
                    'name' => $dentist->name,
                ];
            }),
            'appointment' => new AppointmentResource($this->whenLoaded('appointment')),
            'components' => PatientTreatmentComponentResource::collection($this->whenLoaded('components')),
            'appointments' => AppointmentResource::collection($this->whenLoaded('appointments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
