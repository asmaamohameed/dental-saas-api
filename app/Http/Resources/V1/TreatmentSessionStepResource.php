<?php

namespace App\Http\Resources\V1;

use App\Models\TreatmentSessionStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TreatmentSessionStep */
class TreatmentSessionStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'treatment_session_id' => $this->treatment_session_id,
            'treatment_template_step_id' => $this->treatment_template_step_id,
            'custom_step_name' => $this->custom_step_name,
            'custom_step_description' => $this->custom_step_description,
            'name' => $this->displayName(),
            'occurrence_number' => $this->occurrence_number,
            'status' => $this->status,
            'notes' => $this->notes,
            'template_step' => $this->whenLoaded('templateStep', fn () => $this->templateStep ? [
                'id' => $this->templateStep->id,
                'name_ar' => $this->templateStep->name_ar,
                'name_en' => $this->templateStep->name_en,
                'is_required' => (bool) $this->templateStep->is_required,
                'is_repeatable' => (bool) $this->templateStep->is_repeatable,
                'step_order' => $this->templateStep->step_order,
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
