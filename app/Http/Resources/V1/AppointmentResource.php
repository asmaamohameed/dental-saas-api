<?php

namespace App\Http\Resources\V1;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentVisit;
use App\Models\User;
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
                /**
                 * @var Patient $patient
                 */
                $patient = $this->patient;

                return [
                    'id' => $patient->id,
                    'name' => $patient->full_name,
                ];
            }),
            'doctor' => $this->whenLoaded('doctor', function () {
                /**
                 * @var User $doctor
                 */
                $doctor = $this->doctor;

                return [
                    'id' => $doctor->id,
                    'name' => $doctor->name,
                ];
            }),
            'patient_treatment_visit' => $this->whenLoaded('patientTreatmentVisit', function () {
                /**
                 * @var PatientTreatmentVisit $patientTreatmentVisit
                 */
                $patientTreatmentVisit = $this->patientTreatmentVisit;
                /**
                 * @var PatientTreatment $treatment
                 */
                $treatment = $patientTreatmentVisit->treatment;

                return [
                    'id' => $patientTreatmentVisit->id,
                    'name' => $patientTreatmentVisit->name,
                    'visit_order' => $patientTreatmentVisit->visit_order,
                    'status' => $patientTreatmentVisit->status,
                    'treatment' => $patientTreatmentVisit->relationLoaded('treatment') ? [
                        'id' => $treatment->id,
                        'tooth_number' => $treatment->tooth_number,
                        'status' => $treatment->status,
                    ] : null,
                ];
            }),
        ];
    }
}
