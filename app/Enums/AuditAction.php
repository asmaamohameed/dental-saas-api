<?php

namespace App\Enums;

enum AuditAction: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case RESTORED = 'restored';
    case TREATMENT_TOOTH_PRICE_CHANGED = 'treatment_tooth_price_changed';
    case TREATMENT_TEMPLATE_DEFAULT_PRICE_CHANGED = 'treatment_template_default_price_changed';
}
