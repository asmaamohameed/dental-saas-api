<?php

namespace App\Enums;

enum InventoryItemListStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case All = 'all';
}
