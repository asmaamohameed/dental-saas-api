<?php

namespace App\Http\Resources\V1;

use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryItem
 */
class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'unit' => $this->unit,
            'current_quantity' => (float) $this->current_quantity,
            'minimum_threshold' => $this->minimum_threshold !== null ? (float) $this->minimum_threshold : null,
            'is_low_stock' => $this->minimum_threshold !== null
                && bccomp((string) $this->current_quantity, (string) $this->minimum_threshold, 2) <= 0,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
