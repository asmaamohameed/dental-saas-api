<?php

namespace App\Http\Resources\V1;

use App\Models\TreatmentPlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TreatmentPlan */
class TreatmentPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'title' => $this->title,
            'status' => $this->status,
            'treatments' => PatientTreatmentResource::collection($this->whenLoaded('treatments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
