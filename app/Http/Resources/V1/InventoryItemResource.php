<?php

namespace App\Http\Resources\V1;

use App\Models\InventoryItem;
use App\Support\Inventory\InventoryExpiry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryItem
 */
class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $daysToExpiry = $this->expiry_date !== null
            ? InventoryExpiry::daysToExpiry($this->expiry_date)
            : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'unit' => $this->unit,
            'cost_price' => (float) $this->cost_price,
            'expiry_date' => $this->expiry_date?->format('Y-m-d'),
            'supplier' => $this->supplier,
            'current_quantity' => (float) $this->current_quantity,
            'minimum_threshold' => $this->minimum_threshold !== null ? (float) $this->minimum_threshold : null,
            'is_low_stock' => $this->minimum_threshold !== null
                && bccomp((string) $this->current_quantity, (string) $this->minimum_threshold, 2) <= 0,
            'is_expired' => $daysToExpiry !== null && $daysToExpiry < 0,
            'is_expiring_soon' => $daysToExpiry !== null && $daysToExpiry >= 0 && $daysToExpiry <= InventoryExpiry::warningDays(),
            'days_to_expiry' => $daysToExpiry,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
