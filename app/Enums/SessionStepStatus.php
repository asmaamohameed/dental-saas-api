<?php

namespace App\Enums;

enum SessionStepStatus: string
{
    case DONE = 'done';
    case SKIPPED = 'skipped';
}
