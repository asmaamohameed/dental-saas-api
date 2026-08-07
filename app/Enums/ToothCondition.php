<?php

namespace App\Enums;

enum ToothCondition: string
{
    case HEALTHY = 'healthy';
    case DECAYED = 'decayed';
    case FILLED = 'filled';
    case MISSING = 'missing';
    case CROWN = 'crown';
    case ROOT_CANAL = 'root_canal';
    case NEEDS_EXTRACTION = 'needs_extraction';
    case IMPACTED = 'impacted';
}
