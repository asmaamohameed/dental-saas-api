<?php

namespace App\Enums;

enum TreatmentToothStatus: string
{
    case PLANNED = 'planned';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case SKIPPED = 'skipped';
}
