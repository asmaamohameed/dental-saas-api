<?php

namespace App\Enums;

enum PatientTreatmentStatus: string
{
    case PROPOSED = 'proposed';
    case ACCEPTED = 'accepted';
    case PLANNED = 'planned';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case PAUSED = 'paused';
    case CANCELLED = 'cancelled';
}
