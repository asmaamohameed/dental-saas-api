<?php

namespace App\Enums;

enum AuditAction: string
{
    case CREATED = 'created';
    case UPDATED = 'updated';
    case DELETED = 'deleted';
    case RESTORED = 'restored';
}
