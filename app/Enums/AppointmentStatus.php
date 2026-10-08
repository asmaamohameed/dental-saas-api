<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case SCHEDULED = 'scheduled';
    case CHECKED_IN = 'checked_in';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case NO_SHOW = 'no_show';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::SCHEDULED => in_array($target, [self::CHECKED_IN, self::CANCELLED, self::NO_SHOW], true),
            self::CHECKED_IN => in_array($target, [self::IN_PROGRESS, self::COMPLETED, self::CANCELLED, self::NO_SHOW], true),
            self::IN_PROGRESS => $target === self::COMPLETED,
            self::COMPLETED => in_array($target, [self::CANCELLED, self::NO_SHOW], true),
            self::CANCELLED, self::NO_SHOW => false,
        };
    }
}
