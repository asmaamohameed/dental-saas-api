<?php

namespace App\Support;

final class WorkingDay
{
    public const SATURDAY = 'saturday';

    public const SUNDAY = 'sunday';

    public const MONDAY = 'monday';

    public const TUESDAY = 'tuesday';

    public const WEDNESDAY = 'wednesday';

    public const THURSDAY = 'thursday';

    public const FRIDAY = 'friday';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return [
            self::SATURDAY,
            self::SUNDAY,
            self::MONDAY,
            self::TUESDAY,
            self::WEDNESDAY,
            self::THURSDAY,
            self::FRIDAY,
        ];
    }
}
