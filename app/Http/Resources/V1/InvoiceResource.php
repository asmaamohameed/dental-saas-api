<?php

namespace App\Http\Resources\V1;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\User;
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
            'patient' => $this->whenLoaded('patient', function (Patient $patient) {
                return [
                    'id' => $patient->id,
                    'full_name' => $patient->full_name,
                    'phone' => $patient->phone,
                ];
            }),
            'appointment' => $this->whenLoaded('appointment', function (Appointment $appointment) {
                return [
                    'id' => $appointment->id,
                    'scheduled_at' => $appointment->scheduled_at,
                    'status' => $appointment->status,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function (User $creator) {
                return [
                    'id' => $creator->id,
                    'name' => $creator->name,
                ];
            }),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
