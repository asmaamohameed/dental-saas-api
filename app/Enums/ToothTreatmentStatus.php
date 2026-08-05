<?php

namespace App\Enums;

enum ToothTreatmentStatus: string
{
    case PLANNED = 'planned';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
}
