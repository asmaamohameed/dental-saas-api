<?php

namespace App\Enums;

enum TreatmentPriority: string
{
    case HIGH = 'high';
    case MEDIUM = 'medium';
    case LOW = 'low';
    case ROUTINE = 'routine';
}
