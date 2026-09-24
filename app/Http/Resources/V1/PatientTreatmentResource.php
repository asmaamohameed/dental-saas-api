<?php

namespace App\Http\Resources\V1;

use App\Models\PatientTreatment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientTreatment */
class PatientTreatmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'treatment_plan_id' => $this->treatment_plan_id,
            'treatment_template_id' => $this->treatment_template_id,
            'treatment_template_version' => (int) $this->treatment_template_version,
            'agreed_price' => (float) $this->agreed_price,
            'clinical_status' => $this->clinical_status,
            'status' => $this->clinical_status,
            'cancellation_reason' => $this->cancellation_reason,
            'financial_status' => $this->financial_status,
            'consent_status' => $this->consent_status,
            'consent_document_ref' => $this->consent_document_ref,
            'parent_treatment_id' => $this->parent_treatment_id,
            'treatment_type' => $this->treatment_type,
            'dentist_id' => $this->dentist_id,
            'doctor_id' => $this->dentist_id,
            'diagnosis' => $this->diagnosis,
            'priority' => $this->priority,
            'notes' => $this->notes,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'created_by' => $this->created_by,
            'tooth_numbers' => $this->whenLoaded('teeth', fn () => $this->teeth->pluck('tooth_number')->values()->all()),
            'invoice_id' => $this->whenLoaded('invoiceItems', fn () => $this->invoiceItems->sortByDesc('created_at')->first()?->invoice_id),
            'patient' => $this->whenLoaded('patient', fn () => [
                'id' => $this->patient?->id,
                'full_name' => $this->patient?->full_name,
                'phone' => $this->patient?->phone,
            ]),
            'plan' => $this->whenLoaded('plan', fn () => $this->plan ? [
                'id' => $this->plan->id,
                'title' => $this->plan->title,
                'status' => $this->plan->status,
            ] : null),
            'template' => new TreatmentTemplateResource($this->whenLoaded('template')),
            'dentist' => $this->whenLoaded('dentist', fn () => $this->dentist ? [
                'id' => $this->dentist->id,
                'name' => $this->dentist->name,
            ] : null),
            'doctor' => $this->whenLoaded('dentist', fn () => $this->dentist ? [
                'id' => $this->dentist->id,
                'name' => $this->dentist->name,
            ] : null),
            'parent_treatment' => $this->whenLoaded('parentTreatment', fn () => $this->parentTreatment ? [
                'id' => $this->parentTreatment->id,
                'clinical_status' => $this->parentTreatment->clinical_status,
            ] : null),
            'sessions' => TreatmentSessionResource::collection($this->whenLoaded('sessions')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
