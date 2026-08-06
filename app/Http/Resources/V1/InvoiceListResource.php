<?php

namespace App\Http\Resources\V1;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'patient_name' => $this->whenLoaded('patient', fn () => $this->patient->full_name),
            'total_amount' => (float) $this->total_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
