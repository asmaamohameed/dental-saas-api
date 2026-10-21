<?php

namespace App\Support\LabWorks;

use App\Enums\LabWorkStatus;
use Illuminate\Support\Carbon;

/**
 * Clinic-local calendar dates for lab work. Stored values stay date-only.
 */
final class LabWorkTiming
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

    /** Sunday of the Monday–Sunday week that contains the clinic's today. */
    public static function endOfWeekDateString(): string
    {
        return self::today()->endOfWeek(Carbon::SUNDAY)->toDateString();
    }

    public static function parseDate(Carbon|string $date): Carbon
    {
        $dateString = $date instanceof Carbon
            ? $date->format('Y-m-d')
            : $date;

        return Carbon::parse($dateString, self::clinicTimezone())->startOfDay();
    }

    /** Signed whole days from $fromDate to $toDate. */
    public static function signedDays(string $fromDate, string $toDate): int
    {
        return (int) self::parseDate($fromDate)->diffInDays(self::parseDate($toDate), false);
    }

    /**
     * @return array{kind: 'overdue'|'due_today'|'due_in'|'on_time'|'late'|null, days: int|null}
     */
    public static function describe(LabWorkStatus $status, string $expectedOn, ?string $receivedOn): array
    {
        if ($status === LabWorkStatus::CANCELLED) {
            return ['kind' => null, 'days' => null];
        }

        if ($status === LabWorkStatus::RECEIVED) {
            if ($receivedOn === null || $receivedOn === '') {
                return ['kind' => 'on_time', 'days' => 0];
            }

            $late = self::signedDays($expectedOn, $receivedOn);

            if ($late > 0) {
                return ['kind' => 'late', 'days' => $late];
            }

            return ['kind' => 'on_time', 'days' => 0];
        }

        $daysUntil = self::signedDays(self::todayDateString(), $expectedOn);

        if ($daysUntil < 0) {
            return ['kind' => 'overdue', 'days' => abs($daysUntil)];
        }

        if ($daysUntil === 0) {
            return ['kind' => 'due_today', 'days' => 0];
        }

        return ['kind' => 'due_in', 'days' => $daysUntil];
    }
}
