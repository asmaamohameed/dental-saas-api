<?php

namespace App\Http\Resources\V1;

use App\Models\TreatmentTemplateStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TreatmentTemplateStep */
class TreatmentTemplateStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'treatment_template_id' => $this->treatment_template_id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $this->description,
            'step_order' => $this->step_order,
            'is_required' => (bool) $this->is_required,
            'is_repeatable' => (bool) $this->is_repeatable,
            'default_duration_minutes' => $this->default_duration_minutes,
            'components' => TreatmentTemplateComponentResource::collection($this->whenLoaded('components')),
        ];
    }
}
