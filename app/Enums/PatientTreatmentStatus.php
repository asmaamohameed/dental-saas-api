<?php

namespace App\Enums;

enum PatientTreatmentStatus: string
{
    case PLANNED = 'planned';
    case IN_PROGRESS = 'in_progress';
    case ON_HOLD = 'on_hold';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PLANNED => [self::IN_PROGRESS, self::ON_HOLD, self::CANCELLED],
            self::IN_PROGRESS => [self::ON_HOLD, self::COMPLETED, self::CANCELLED, self::FAILED],
            self::ON_HOLD => [self::IN_PROGRESS, self::CANCELLED],
            self::COMPLETED => [self::FAILED],
            self::CANCELLED, self::FAILED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::CANCELLED, self::FAILED], true);
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::PLANNED, self::IN_PROGRESS, self::ON_HOLD], true);
    }
}
