<?php

namespace App\Support\Inventory;

use Illuminate\Support\Carbon;

/**
 * Clinic-local calendar dates for inventory expiry (storage remains UTC dates).
 */
final class InventoryExpiry
{
    public static function clinicTimezone(): string
    {
        return (string) config('clinic.timezone', 'Africa/Cairo');
    }

    public static function today(): Carbon
    {
        return Carbon::now(self::clinicTimezone())->startOfDay();
    }

    public static function todayDateString(): string
    {
        return self::today()->toDateString();
    }

    public static function warningDays(): int
    {
        return max(0, (int) config('inventory.expiry_warning_days', 30));
    }

    public static function warningEndDateString(): string
    {
        return self::today()->addDays(self::warningDays())->toDateString();
    }

    public static function parseExpiryDate(Carbon|string $expiryDate): Carbon
    {
        $dateString = $expiryDate instanceof Carbon
            ? $expiryDate->format('Y-m-d')
            : $expiryDate;

        return Carbon::parse($dateString, self::clinicTimezone())->startOfDay();
    }

    /** Signed day count: negative when expired, zero on expiry day. */
    public static function daysToExpiry(Carbon|string $expiryDate): int
    {
        $expiry = self::parseExpiryDate($expiryDate);

        return (int) self::today()->diffInDays($expiry, false);
    }

    public static function isExpired(Carbon|string $expiryDate): bool
    {
        return self::daysToExpiry($expiryDate) < 0;
    }

    public static function isExpiringSoon(Carbon|string $expiryDate): bool
    {
        $days = self::daysToExpiry($expiryDate);

        return $days >= 0 && $days <= self::warningDays();
    }
}
