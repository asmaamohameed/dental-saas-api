<?php

namespace App\Http\Resources\V1;

use App\Models\TreatmentTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TreatmentTemplate */
class TreatmentTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'category' => $this->category,
            'description' => $this->description,
            'default_price' => (float) $this->default_price,
            'estimated_duration_minutes' => $this->estimated_duration_minutes,
            'visit_type' => $this->visit_type,
            'is_active' => $this->is_active,
            'visits' => TreatmentTemplateVisitResource::collection($this->whenLoaded('visits')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
