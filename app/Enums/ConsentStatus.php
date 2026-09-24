<?php

namespace App\Enums;

enum ConsentStatus: string
{
    case NOT_REQUIRED = 'not_required';
    case PENDING = 'pending';
    case OBTAINED = 'obtained';
    case DECLINED = 'declined';
}
