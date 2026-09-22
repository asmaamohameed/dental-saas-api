<?php

namespace App\Http\Resources\V1;

use App\Models\Component;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Component
 */
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
            'inventory_item_id' => $this->inventory_item_id,
            'is_active' => $this->is_active,
            'inventory_item' => $this->whenLoaded('inventoryItem', function () {
                /**
                 * @var InventoryItem $inventoryItem
                 */
                $inventoryItem = $this->inventoryItem;

                return [
                    'id' => $inventoryItem->id,
                    'name' => $inventoryItem->name,
                    'unit' => $inventoryItem->unit,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
