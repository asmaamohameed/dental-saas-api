<?php

namespace App\Enums;

enum InventoryItemListExpiry: string
{
    case Expiring = 'expiring';
    case Expired = 'expired';
    case Alert = 'alert';
}
