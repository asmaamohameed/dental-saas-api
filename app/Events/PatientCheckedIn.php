<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PatientCheckedIn implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $appointmentId;
    public string $patientId;
    public string $patientName;
    public string $tenantId;
    public string $doctorId;
    public string $checkedInAt;

    public function __construct(Appointment $appointment)
    {
        $this->appointmentId = (string) $appointment->id;
        $this->patientId     = (string) $appointment->patient_id;
        $this->patientName   = $appointment->patient->full_name;
        $this->tenantId      = (string) $appointment->tenant_id;
        $this->doctorId      = (string) $appointment->doctor_id;
        $this->checkedInAt   = now()->toIso8601String();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->tenantId}.doctor.{$this->doctorId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'patient.checked-in';
    }

    public function broadcastWith(): array
    {
        return [
            'appointment_id' => $this->appointmentId,
            'patient_id'     => $this->patientId,
            'patient_name'   => $this->patientName,
            'checked_in_at'  => $this->checkedInAt,
        ];
    }
}