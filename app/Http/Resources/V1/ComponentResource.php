<?php

namespace App\Http\Resources\V1;

use App\Models\Component;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Component */
class ComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_ar' => $this->name_ar,
            'name_en' => $this->name_en,
            'default_price' => (float) $this->default_price,
            'unit' => $this->unit,
            'current_quantity' => (float) $this->current_quantity,
            'minimum_threshold' => $this->minimum_threshold !== null ? (float) $this->minimum_threshold : null,
            'is_low_stock' => (bool) $this->is_low_stock,
            'inventory_item_id' => $this->inventory_item_id,
            'is_active' => $this->is_active,
            'inventory_item' => $this->whenLoaded('inventoryItem', fn() => [
                'id' => $this->inventoryItem?->id,
                'name' => $this->inventoryItem?->name,
                'unit' => $this->inventoryItem?->unit,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
