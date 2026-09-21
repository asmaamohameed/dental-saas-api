<?php

namespace App\Enums;

enum AppointmentType: string
{
    case CONSULTATION = 'consultation';
    case TREATMENT_VISIT = 'treatment_visit';
    case FOLLOW_UP = 'follow_up';
    case EMERGENCY = 'emergency';
}
