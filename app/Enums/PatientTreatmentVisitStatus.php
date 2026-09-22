<?php

namespace App\Enums;

enum PatientTreatmentVisitStatus: string
{
    case PENDING = 'pending';
    case PLANNED = 'planned';
    case SCHEDULED = 'scheduled';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
}

