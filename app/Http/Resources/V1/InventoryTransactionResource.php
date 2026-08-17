<?php

namespace App\Http\Resources\V1;

use App\Models\InventoryTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryTransaction
 */
class InventoryTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'inventory_item' => $this->whenLoaded('inventoryItem', function () {
                return new InventoryItemResource($this->inventoryItem);
            }),
            'type' => $this->type,
            'quantity' => (float) $this->quantity,
            'reason' => $this->reason,
            'performed_by' => $this->whenLoaded('performer', function () {
                return $this->performer ? [
                    'id' => $this->performer->id,
                    'name' => $this->performer->name,
                ] : null;
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
