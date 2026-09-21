<?php

namespace App\Http\Resources\V1;

use App\Models\TreatmentTemplateVisit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TreatmentTemplateVisit */
class TreatmentTemplateVisitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'treatment_template_id' => $this->treatment_template_id,
            'visit_order' => $this->visit_order,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'description' => $this->description,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'components' => TreatmentTemplateComponentResource::collection($this->whenLoaded('components')),
        ];
    }
}
