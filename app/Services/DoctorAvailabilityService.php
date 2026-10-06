<?php

namespace App\Services;

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

        $from = Carbon::parse($dateFrom ?? $dateTo)->startOfDay();
        $to = Carbon::parse($dateTo ?? $dateFrom)->endOfDay();

        $weekday = $from->isSameDay($to)
            ? strtolower($from->englishDayOfWeek)
            : null;

        if ($weekday !== null && ! in_array($weekday, WorkingDay::values(), true)) {
            $weekday = null;
        }

        return compact('from', 'to', 'weekday');
    }

    /**
     * @return Collection<int, array<string, mixed>>
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

        $rows = Appointment::query()
            ->selectRaw(
                'doctor_id,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as waiting_count,
                MAX(CASE WHEN status = ? THEN 1 ELSE 0 END) as in_visit,
                MIN(CASE WHEN status = ? AND scheduled_at >= ? THEN scheduled_at END) as next_booking_at',
                [
                    AppointmentStatus::CHECKED_IN->value,
                    AppointmentStatus::IN_PROGRESS->value,
                    AppointmentStatus::SCHEDULED->value,
                    $now,
                ]
            )
            ->where('tenant_id', $tenantId)
            ->whereBetween('scheduled_at', [$from, $to])
            ->whereIn('doctor_id', $doctorIds)
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

            return [
                'id' => $doctor->id,
                'name' => $doctor->name,
                'waiting_count' => $waitingCount,
                'in_visit' => $inVisit,
                'state' => ($inVisit || $waitingCount > 0) ? 'busy' : 'free',
                'next_booking_at' => $nextBooking !== null
                    ? Carbon::parse($nextBooking)->toIso8601String()
                    : null,
                'works_today' => $worksToday,
            ];
        });
    }

    public function listForCurrentTenant(?string $dateFrom, ?string $dateTo): Collection
    {
        $tenantId = (string) app(CurrentTenant::class)->id();
        $window = $this->resolveWindow($dateFrom, $dateTo);

        return $this->listForTenant($tenantId, $window['from'], $window['to'], $window['weekday']);
    }
}
