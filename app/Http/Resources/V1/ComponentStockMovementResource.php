<?php

namespace App\Http\Resources\V1;

use App\Models\ComponentStockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ComponentStockMovement */
class ComponentStockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'component_id' => $this->component_id,
            'type' => $this->type,
            'quantity' => (int) $this->quantity,
            'created_at' => $this->created_at?->toISOString(),
            'performed_by' => $this->whenLoaded('performer', fn () => $this->performer ? [
                'id' => $this->performer->id,
                'name' => $this->performer->name,
            ] : null),
        ];
    }
}
