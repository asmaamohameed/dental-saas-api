<?php

namespace App\Http\Resources\V1;

use App\Models\TreatmentTemplateComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TreatmentTemplateComponent */
class TreatmentTemplateComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'component_id' => $this->component_id,
            'quantity' => (float) $this->quantity,
            'free_quantity' => (float) $this->free_quantity,
            'unit_price' => (float) $this->unit_price,
            'component' => new ComponentResource($this->whenLoaded('component')),
        ];
    }
}
