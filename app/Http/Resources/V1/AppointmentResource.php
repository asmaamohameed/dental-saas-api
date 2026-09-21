<?php

namespace App\Http\Resources\V1;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'doctor_id' => $this->doctor_id,
            'patient_treatment_visit_id' => $this->patient_treatment_visit_id,
            'scheduled_at' => $this->scheduled_at,
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status,
            'appointment_type' => $this->appointment_type,
            'notes' => $this->notes,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'patient' => $this->whenLoaded('patient', function () {
                return [
                    'id' => $this->patient->id,
                    'name' => $this->patient->full_name,
                ];
            }),
            'doctor' => $this->whenLoaded('doctor', function () {
                return [
                    'id' => $this->doctor->id,
                    'name' => $this->doctor->name,
                ];
            }),
            'patient_treatment_visit' => $this->whenLoaded('patientTreatmentVisit', function () {
                return [
                    'id' => $this->patientTreatmentVisit->id,
                    'name' => $this->patientTreatmentVisit->name,
                    'visit_order' => $this->patientTreatmentVisit->visit_order,
                    'status' => $this->patientTreatmentVisit->status,
                    'treatment' => $this->patientTreatmentVisit->relationLoaded('treatment') ? [
                        'id' => $this->patientTreatmentVisit->treatment?->id,
                        'tooth_number' => $this->patientTreatmentVisit->treatment?->tooth_number,
                        'status' => $this->patientTreatmentVisit->treatment?->status,
                    ] : null,
                ];
            }),
        ];
    }
}
