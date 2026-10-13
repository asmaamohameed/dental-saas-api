<?php

namespace App\Support\Validation;

final class MoneyAmountRules
{
    /**
     * Whole currency units (min 0). Stored as decimal in DB; API accepts integers only.
     *
     * @return list<string>
     */
    /**
     * @return list<string>
     */
    public static function wholeUnitBase(): array
    {
        return ['integer', 'min:0'];
    }

    public static function wholeUnit(bool $required = false): array
    {
        return $required
            ? array_merge(['required'], self::wholeUnitBase())
            : array_merge(['nullable'], self::wholeUnitBase());
    }

    /**
     * @return list<string>
     */
    public static function wholeUnitSometimes(): array
    {
        return array_merge(['sometimes'], self::wholeUnitBase());
    }
}
