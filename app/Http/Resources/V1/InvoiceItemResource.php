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
            'patient_treatment_id' => $this->patient_treatment_id,
            'description' => $this->description,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'subtotal' => $this->subtotal,
            'patient_treatment' => $this->whenLoaded(
                'patientTreatment',
                fn () => $this->patientTreatment
                    ? new PatientTreatmentResource($this->patientTreatment)
                    : null
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
