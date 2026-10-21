<?php

namespace App\Enums;

enum LabWorkTimingFilter: string
{
    case OVERDUE = 'overdue';
    case DUE_TODAY = 'due_today';
    case DUE_THIS_WEEK = 'due_this_week';
}
