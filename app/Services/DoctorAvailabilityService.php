<?php

namespace App\Services;

use App\DataTransferObjects\DoctorAvailabilityRow;
use App\Enums\AppointmentStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use App\Support\WorkingDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DoctorAvailabilityService
{
    /**
     * @return array{from: Carbon, to: Carbon, weekday: string|null}
     */
    public function resolveWindow(?string $dateFrom, ?string $dateTo): array
    {
        if ($dateFrom === null && $dateTo === null) {
            $from = Carbon::now()->startOfDay();
            $to = Carbon::now()->endOfDay();

            return [
                'from' => $from,
                'to' => $to,
                'weekday' => strtolower($from->englishDayOfWeek),
            ];
        }

        $from = $this->parseWindowBoundary($dateFrom ?? $dateTo, false);
        $to = $this->parseWindowBoundary($dateTo ?? $dateFrom, true);

        $weekday = $from->isSameDay($to)
            ? strtolower($from->englishDayOfWeek)
            : null;

        if ($weekday !== null && ! in_array($weekday, WorkingDay::values(), true)) {
            $weekday = null;
        }

        return compact('from', 'to', 'weekday');
    }

    /**
     * @return Collection<int, DoctorAvailabilityRow>
     */
    public function listForTenant(string $tenantId, Carbon $from, Carbon $to, ?string $weekday): Collection
    {
        $doctors = User::query()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->where(function ($query) use ($tenantId) {
                User::constrainUsersWithClinicRole($query, $tenantId, UserRole::DOCTOR->value);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'working_days']);

        if ($doctors->isEmpty()) {
            return collect();
        }

        $doctorIds = $doctors->pluck('id')->all();
        $now = Carbon::now();

        $checkedIn = AppointmentStatus::CHECKED_IN->value;
        $inProgress = AppointmentStatus::IN_PROGRESS->value;
        $scheduled = AppointmentStatus::SCHEDULED->value;

        $rows = Appointment::query()
            ->selectRaw(
                'doctor_id,
                SUM(CASE WHEN status = ? AND checked_in_at IS NOT NULL AND checked_in_at >= ? AND checked_in_at <= ? THEN 1 ELSE 0 END) as waiting_count,
                MAX(CASE WHEN status = ? AND started_at IS NOT NULL AND started_at >= ? AND started_at <= ? THEN 1 ELSE 0 END) as in_visit,
                MIN(CASE WHEN status = ? AND scheduled_at >= ? AND scheduled_at >= ? AND scheduled_at <= ? THEN scheduled_at END) as next_booking_at',
                [
                    $checkedIn,
                    $from,
                    $to,
                    $inProgress,
                    $from,
                    $to,
                    $scheduled,
                    $now,
                    $from,
                    $to,
                ]
            )
            ->where('tenant_id', $tenantId)
            ->whereIn('doctor_id', $doctorIds)
            ->where(function ($query) use ($from, $to, $now, $checkedIn, $inProgress, $scheduled) {
                $query->where(function ($inner) use ($from, $to, $checkedIn) {
                    $inner->where('status', $checkedIn)
                        ->whereNotNull('checked_in_at')
                        ->whereBetween('checked_in_at', [$from, $to]);
                })->orWhere(function ($inner) use ($from, $to, $inProgress) {
                    $inner->where('status', $inProgress)
                        ->whereNotNull('started_at')
                        ->whereBetween('started_at', [$from, $to]);
                })->orWhere(function ($inner) use ($from, $to, $now, $scheduled) {
                    $inner->where('status', $scheduled)
                        ->whereBetween('scheduled_at', [$from, $to])
                        ->where('scheduled_at', '>=', $now);
                });
            })
            ->groupBy('doctor_id')
            ->get()
            ->keyBy('doctor_id');

        return $doctors->map(function (User $doctor) use ($rows, $weekday) {
            $stats = $rows->get($doctor->id);
            $waitingCount = (int) ($stats->waiting_count ?? 0);
            $inVisit = (bool) ($stats->in_visit ?? false);
            $nextBooking = $stats->next_booking_at ?? null;

            $worksToday = null;
            if ($weekday !== null) {
                $worksToday = in_array($weekday, $doctor->working_days ?? [], true);
            }

            $state = ($inVisit || $waitingCount > 0) ? 'busy' : 'free';

            return new DoctorAvailabilityRow(
                id: $doctor->id,
                name: $doctor->name,
                waiting_count: $waitingCount,
                in_visit: $inVisit,
                state: $state,
                next_booking_at: $nextBooking !== null
                    ? Carbon::parse($nextBooking)->toIso8601String()
                    : null,
                works_today: $worksToday,
            );
        });
    }

    /**
     * @return Collection<int, DoctorAvailabilityRow>
     */
    public function listForCurrentTenant(?string $dateFrom, ?string $dateTo): Collection
    {
        $tenantId = (string) app(CurrentTenant::class)->id();
        $window = $this->resolveWindow($dateFrom, $dateTo);

        return $this->listForTenant($tenantId, $window['from'], $window['to'], $window['weekday']);
    }

    private function parseWindowBoundary(string $value, bool $isEnd): Carbon
    {
        $trimmed = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) === 1) {
            $parsed = Carbon::parse($trimmed);

            return $isEnd ? $parsed->copy()->endOfDay() : $parsed->copy()->startOfDay();
        }

        return Carbon::parse($trimmed);
    }
}
