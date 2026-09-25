<?php

namespace App\Enums;

enum ExpenseCategory: string
{
    case ELECTRICITY = 'electricity';
    case INTERNET = 'internet';
    case RENT = 'rent';
    case WATER = 'water';
    case SALARY = 'salary';
    case LAB = 'lab';
    case OTHER = 'other';
}
