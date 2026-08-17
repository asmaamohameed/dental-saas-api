<?php

namespace App\Enums;

enum InventoryTransactionType: string
{
    case IN = 'in';
    case OUT = 'out';
}
