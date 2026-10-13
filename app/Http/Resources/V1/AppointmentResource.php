<?php

namespace App\Http\Resources\V1;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\TreatmentTemplate;
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
            'booked_doctor_id' => $this->booked_doctor_id,
            'scheduled_at' => $this->scheduled_at,
            'checked_in_at' => $this->checked_in_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'service_id' => $this->service_id,
            'fee' => $this->fee,
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
            'service' => $this->whenLoaded('service', function () {
                /** @var TreatmentTemplate $service */
                $service = $this->service;

                return [
                    'id' => $service->id,
                    'name_ar' => $service->name_ar,
                    'name_en' => $service->name_en,
                    'category' => $service->category,
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
            'treatment_sessions' => TreatmentSessionResource::collection($this->whenLoaded('treatmentSessions')),
        ];
    }
}
