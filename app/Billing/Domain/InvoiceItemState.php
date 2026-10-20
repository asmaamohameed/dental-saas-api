<?php

namespace App\Billing\Domain;

enum InvoiceItemState: string
{
    case PLANNED = 'planned';
    case IN_PROGRESS = 'in_progress';
    case DONE = 'done';
    case SKIPPED = 'skipped';
    case VOIDED = 'voided';
    case DELETED = 'deleted';
}
