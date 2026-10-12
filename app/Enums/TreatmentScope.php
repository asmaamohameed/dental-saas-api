<?php

namespace App\Enums;

enum TreatmentScope: string
{
    case TOOTH = 'tooth';
    case QUADRANT = 'quadrant';
    case ARCH = 'arch';
    case FULL_MOUTH = 'full_mouth';
}
