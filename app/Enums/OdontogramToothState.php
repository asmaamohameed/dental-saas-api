<?php

namespace App\Enums;

enum OdontogramToothState: string
{
    case IN_PROGRESS = 'in_progress';
    case PLANNED = 'planned';
    case DONE = 'done';
    case NONE = 'none';

    /**
     * Legacy map for `treatment_status_by_tooth` consumers.
     */
    public function legacyTreatmentStatusKey(): ?string
    {
        return match ($this) {
            self::IN_PROGRESS => 'in_progress',
            self::PLANNED => 'planned',
            self::DONE => 'completed',
            self::NONE => null,
        };
    }
}
