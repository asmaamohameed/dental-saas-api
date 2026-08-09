<?php

namespace App\Enums;

enum UserRole: string
{
    case OWNER = 'owner';
    case DOCTOR = 'doctor';
    case RECEPTIONIST = 'receptionist';

    public function isOwner(): bool
    {
        return $this === self::OWNER;
    }

    public function isDoctor(): bool
    {
        return $this === self::DOCTOR;
    }

    public function isReceptionist(): bool
    {
        return $this === self::RECEPTIONIST;
    }
}
