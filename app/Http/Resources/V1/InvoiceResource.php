<?php

namespace App\Http\Resources\V1;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'patient_id' => $this->patient_id,
            'appointment_id' => $this->appointment_id,
            'created_by' => $this->created_by,
            'total_amount' => (float) $this->total_amount,
            'remaining_amount' => (float) $this->remaining_amount,
            'status' => $this->status,
            'patient' => $this->whenLoaded('patient', function () {
                return [
                    'id' => $this->patient->id,
                    'full_name' => $this->patient->full_name,
                    'phone' => $this->patient->phone,
                ];
            }),
            'appointment' => $this->whenLoaded('appointment', function () {
                return [
                    'id' => $this->appointment->id,
                    'scheduled_at' => $this->appointment->scheduled_at,
                    'status' => $this->appointment->status,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->id,
                    'name' => $this->creator->name,
                ];
            }),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
