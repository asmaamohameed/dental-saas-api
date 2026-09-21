<?php

namespace App\Enums;

enum TreatmentCategory: string
{
    case MEDICAL = 'medical';
    case PREVENTIVE = 'preventive';
    case COSMETIC = 'cosmetic';
    case ORTHODONTIC = 'orthodontic';
    case SURGICAL = 'surgical';
    case OTHER = 'other';
}
