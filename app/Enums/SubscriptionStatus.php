<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case TRIAL = 'trial';
    case ACTIVE = 'active';
    case EXPIRED = 'expired';
}
