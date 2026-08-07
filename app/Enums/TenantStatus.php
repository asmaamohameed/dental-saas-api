<?php

namespace App\Enums;

enum TenantStatus: string
{
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';
}
