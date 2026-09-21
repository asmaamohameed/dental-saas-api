<?php

namespace App\Http\Resources\V1;

use App\Models\InvoiceItem;
use App\Models\Service;
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
            'patient_treatment_id' => $this->patient_treatment_id,
            'patient_treatment_visit_id' => $this->patient_treatment_visit_id,
            'description' => $this->description,
            'price' => $this->price,
            'quantity' => $this->quantity,
            'subtotal' => $this->subtotal,
            'service' => $this->whenLoaded('service', function (?Service $service) {
                if (! $service) {
                    return null;
                }

                return [
                    'id' => $service->id,
                    'name_ar' => $service->name_ar,
                    'name_en' => $service->name_en,
                ];
            }),
            'patient_treatment' => $this->whenLoaded('patientTreatment', function () {
                return [
                    'id' => $this->patientTreatment?->id,
                    'tooth_number' => $this->patientTreatment?->tooth_number,
                    'status' => $this->patientTreatment?->status,
                    'actual_price' => (float) $this->patientTreatment?->actual_price,
                    'template' => $this->patientTreatment?->relationLoaded('template') ? [
                        'id' => $this->patientTreatment?->template?->id,
                        'name_ar' => $this->patientTreatment?->template?->name_ar,
                        'name_en' => $this->patientTreatment?->template?->name_en,
                    ] : null,
                ];
            }),
            'patient_treatment_visit' => $this->whenLoaded('patientTreatmentVisit', function () {
                return [
                    'id' => $this->patientTreatmentVisit?->id,
                    'name' => $this->patientTreatmentVisit?->name,
                    'visit_order' => $this->patientTreatmentVisit?->visit_order,
                    'status' => $this->patientTreatmentVisit?->status,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
