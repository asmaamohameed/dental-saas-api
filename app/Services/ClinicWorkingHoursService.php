<?php

namespace App\Services;

use App\Models\Tenant;
use App\Support\WorkingDay;
use Illuminate\Support\Carbon;

class ClinicWorkingHoursService
{
    public const DEFAULT_OPEN = '09:00';

    public const DEFAULT_CLOSE = '21:00';

    /**
     * @return array<string, array{is_open: bool, open: string, close: string}>
     */
    public function defaultSchedule(): array
    {
        $schedule = [];
        foreach (WorkingDay::values() as $day) {
            $schedule[$day] = [
                'is_open' => true,
                'open' => self::DEFAULT_OPEN,
                'close' => self::DEFAULT_CLOSE,
            ];
        }

        return $schedule;
    }

    /**
     * @return array<string, array{is_open: bool, open: string, close: string}>
     */
    public function resolveForTenant(Tenant $tenant): array
    {
        $stored = $tenant->working_hours;
        if (! is_array($stored) || $stored === []) {
            return $this->defaultSchedule();
        }

        return $this->normalizeSchedule($stored);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, array{is_open: bool, open: string, close: string}>
     */
    public function normalizeSchedule(array $raw): array
    {
        $schedule = $this->defaultSchedule();

        foreach (WorkingDay::values() as $day) {
            if (! isset($raw[$day]) || ! is_array($raw[$day])) {
                continue;
            }

            $entry = $raw[$day];
            $isOpen = (bool) ($entry['is_open'] ?? false);
            $schedule[$day] = [
                'is_open' => $isOpen,
                'open' => $this->normalizeTimeString($entry['open'] ?? self::DEFAULT_OPEN),
                'close' => $this->normalizeTimeString($entry['close'] ?? self::DEFAULT_CLOSE),
            ];
        }

        return $schedule;
    }

    public function clinicTimezone(): string
    {
        return (string) config('clinic.timezone', 'Africa/Cairo');
    }

    /**
     * @param  array<string, array{is_open: bool, open: string, close: string}>  $schedule
     */
    public function isAppointmentWithinHours(Carbon $scheduledAt, int $durationMinutes, array $schedule): bool
    {
        return $this->violationMessage($scheduledAt, $durationMinutes, $schedule) === null;
    }

    /**
     * @param  array<string, array{is_open: bool, open: string, close: string}>  $schedule
     */
    public function violationMessage(Carbon $scheduledAt, int $durationMinutes, array $schedule): ?string
    {
        $local = $scheduledAt->copy()->timezone($this->clinicTimezone());
        $weekday = strtolower($local->englishDayOfWeek);

        if (! isset($schedule[$weekday])) {
            return __('clinic.hours.closed_day');
        }

        $day = $schedule[$weekday];
        if (! $day['is_open']) {
            return __('clinic.hours.closed_day');
        }

        $openMinutes = $this->timeToMinutes($day['open']);
        $closeMinutes = $this->timeToMinutes($day['close']);
        $startMinutes = $local->hour * 60 + $local->minute;
        $endMinutes = $startMinutes + max(1, $durationMinutes);

        if ($startMinutes < $openMinutes) {
            return __('clinic.hours.before_open');
        }

        if ($endMinutes > $closeMinutes) {
            return __('clinic.hours.after_close');
        }

        return null;
    }

    public function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);
        $hours = (int) $parts[0];
        $minutes = (int) ($parts[1] ?? 0);

        return $hours * 60 + $minutes;
    }

    private function normalizeTimeString(string $time): string
    {
        $parts = explode(':', $time);
        $hours = (int) $parts[0];
        $minutes = (int) ($parts[1] ?? 0);

        return sprintf('%02d:%02d', $hours, $minutes);
    }
}
