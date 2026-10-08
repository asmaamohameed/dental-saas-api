<?php

namespace App\Http\Resources\V1;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Appointment
 */
class UnresolvedAppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /**
         * @var Patient $patient
         */
        $patient = $this->patient;

        /**
         * @var User $doctor
         */
        $doctor = $this->doctor;

        return [
            'id' => $this->id,
            'patient_name' => $patient->full_name,
            'doctor_name' => $doctor->name,
            'scheduled_at' => $this->scheduled_at,
            'status' => $this->status,
        ];
    }
}
