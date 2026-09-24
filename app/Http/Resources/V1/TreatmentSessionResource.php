<?php

namespace App\Http\Resources\V1;

use App\Models\TreatmentSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TreatmentSession */
class TreatmentSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_treatment_id' => $this->patient_treatment_id,
            'appointment_id' => $this->appointment_id,
            'dentist_id' => $this->dentist_id,
            'session_date' => $this->session_date?->toISOString(),
            'status' => $this->status,
            'notes' => $this->notes,
            'dentist' => $this->whenLoaded('dentist', fn () => $this->dentist ? [
                'id' => $this->dentist->id,
                'name' => $this->dentist->name,
            ] : null),
            'appointment' => new AppointmentResource($this->whenLoaded('appointment')),
            'steps' => TreatmentSessionStepResource::collection($this->whenLoaded('steps')),
            'components' => PatientTreatmentComponentResource::collection($this->whenLoaded('components')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
