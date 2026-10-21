<?php

namespace App\Enums;

enum RefundType: string
{
    case PARTIAL = 'partial';
    case FULL = 'full';
}
