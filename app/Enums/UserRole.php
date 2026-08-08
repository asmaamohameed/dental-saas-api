<?php

namespace App\Enums;

enum UserRole: string
{
    case OWNER = 'owner';
    case DOCTOR = 'doctor';
    case RECEPTIONIST = 'receptionist';

    public function can(string $ability): bool
    {
        return match ($this) {
            self::OWNER => true,
            self::DOCTOR => in_array($ability, [
                'appointment.view', 'appointment.create', 'appointment.update', 'appointment.updateStatus', 'appointment.delete',
                'patient.view', 'patient.create', 'patient.update', 'patient.delete',
                'patient.viewMedicalHistory',
                'toothRecord.manage', 'toothRecord.view', 'invoice.view',
            ]),
            self::RECEPTIONIST => in_array($ability, [
                'appointment.view', 'appointment.create', 'appointment.update', 'appointment.updateStatus', 'appointment.delete',
                'patient.view', 'patient.create', 'patient.update', 'patient.delete',
                'toothRecord.view', 'invoice.view', 'invoice.create', 'invoice.update',
            ]),
        };
    }
}
