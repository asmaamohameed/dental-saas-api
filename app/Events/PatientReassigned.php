<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PatientReassigned implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $appointmentId;

    public string $patientId;

    public string $tenantId;

    public string $oldDoctorId;

    public string $newDoctorId;

    public function __construct(Appointment $appointment, string $oldDoctorId)
    {
        $this->appointmentId = (string) $appointment->id;
        $this->patientId = (string) $appointment->patient_id;
        $this->tenantId = (string) $appointment->tenant_id;
        $this->oldDoctorId = $oldDoctorId;
        $this->newDoctorId = (string) $appointment->doctor_id;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.doctor.{$this->oldDoctorId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'patient.reassigned';
    }

    public function broadcastWith(): array
    {
        return [
            'appointment_id' => $this->appointmentId,
            'patient_id' => $this->patientId,
            'old_doctor_id' => $this->oldDoctorId,
            'new_doctor_id' => $this->newDoctorId,
        ];
    }
}
