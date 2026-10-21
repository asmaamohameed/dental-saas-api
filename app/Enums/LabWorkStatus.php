<?php

namespace App\Enums;

/**
 * Lab-work lifecycle. Add new cases here, then list them in allowedTransitions().
 */
enum LabWorkStatus: string
{
    case AT_LAB = 'at_lab';
    case RECEIVED = 'received';
    case CANCELLED = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::AT_LAB => [self::RECEIVED, self::CANCELLED],
            self::RECEIVED => [self::AT_LAB],
            self::CANCELLED => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
