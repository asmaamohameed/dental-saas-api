<?php

namespace App\Http\Resources\V1;

use App\Models\PatientTreatmentComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientTreatmentComponent */
class PatientTreatmentComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'component_id' => $this->component_id,
            'name' => $this->name,
            'unit_price' => (float) $this->unit_price,
            'quantity' => (float) $this->quantity,
            'free_quantity' => (float) $this->free_quantity,
            'unit' => $this->unit,
            'inventory_item_id' => $this->inventory_item_id,
        ];
    }
}
