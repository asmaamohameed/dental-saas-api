<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case ELECTRICITY = 'electricity';
    case INTERNET = 'internet';
    case RENT = 'rent';
    case WATER = 'water';
    case OTHER = 'other';
}
