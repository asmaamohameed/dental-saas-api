<?php

namespace App\Http\Resources\V1;

use App\Models\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InvoiceItem
 */
class InvoiceItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'service_id' => $this->service_id,
            'description' => $this->description,
            'price' => (float) $this->price,
            'quantity' => $this->quantity,
            'subtotal' => (float) $this->subtotal,
            'service' => $this->whenLoaded('service', function () {
                return [
                    'id' => $this->service->id,
                    'name_ar' => $this->service->name_ar,
                    'name_en' => $this->service->name_en,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
