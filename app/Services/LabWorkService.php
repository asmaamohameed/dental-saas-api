<?php

namespace App\Services;

use App\Enums\LabWorkListTab;
use App\Enums\LabWorkStatus;
use App\Enums\LabWorkTimingFilter;
use App\Models\Lab;
use App\Models\LabWork;
use App\Models\Patient;
use App\Support\LabWorks\LabWorkTiming;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class LabWorkService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->filteredQuery($filters)
            ->tap(fn (Builder $query) => $this->applySort($query, $filters))
            ->paginate($perPage);
    }

    /**
     * @return array{at_lab: int, overdue: int, due_this_week: int}
     */
    public function summary(): array
    {
        $today = LabWorkTiming::todayDateString();
        $weekEnd = LabWorkTiming::endOfWeekDateString();
        $atLab = LabWork::query()->where('status', LabWorkStatus::AT_LAB);

        return [
            'at_lab' => (clone $atLab)->count(),
            'overdue' => (clone $atLab)->whereDate('expected_on', '<', $today)->count(),
            'due_this_week' => (clone $atLab)
                ->whereDate('expected_on', '>=', $today)
                ->whereDate('expected_on', '<=', $weekEnd)
                ->count(),
        ];
    }

    /**
     * @param  array{
     *     patient_id: string,
     *     lab_id: string,
     *     description: string,
     *     sent_on: string,
     *     notes?: string|null,
     *     expected_on?: string|null,
     * }  $data
     */
    public function create(array $data): LabWork
    {
        $patient = Patient::query()->whereKey($data['patient_id'])->first();

        if (! $patient) {
            throw ValidationException::withMessages([
                'patient_id' => __('lab.invalid_patient'),
            ]);
        }

        $lab = $this->labOrFail($data['lab_id']);

        if (! $lab->is_active) {
            throw ValidationException::withMessages([
                'lab_id' => __('lab.inactive_lab'),
            ]);
        }

        $sent = $data['sent_on'];
        $expectedOn = $data['expected_on'] ?? null;
        $expected = ($expectedOn !== null && $expectedOn !== '')
            ? $expectedOn
            : $this->defaultExpected($sent, $lab);

        $this->assertExpectedOnOrAfterSent($sent, $expected);

        return $this->loadRelations(LabWork::query()->create([
            'patient_id' => $patient->id,
            'lab_id' => $lab->id,
            'description' => $data['description'],
            'notes' => $this->blankToNull($data['notes'] ?? null),
            'sent_on' => $sent,
            'expected_on' => $expected,
            'status' => LabWorkStatus::AT_LAB,
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(LabWork $work, array $data): LabWork
    {
        if ($work->status === LabWorkStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'status' => __('lab.cancelled_not_editable'),
            ]);
        }

        if (array_key_exists('lab_id', $data) && $data['lab_id'] !== $work->lab_id) {
            $lab = $this->labOrFail((string) $data['lab_id']);

            if (! $lab->is_active) {
                throw ValidationException::withMessages([
                    'lab_id' => __('lab.inactive_lab'),
                ]);
            }
        }

        $sent = (string) ($data['sent_on'] ?? $work->sent_on->format('Y-m-d'));
        $expected = (string) ($data['expected_on'] ?? $work->expected_on->format('Y-m-d'));
        $this->assertExpectedOnOrAfterSent($sent, $expected);

        if (array_key_exists('notes', $data)) {
            $data['notes'] = $this->blankToNull($data['notes']);
        }

        if ($work->status === LabWorkStatus::RECEIVED) {
            $received = array_key_exists('received_on', $data)
                ? $data['received_on']
                : $work->received_on?->format('Y-m-d');
            $this->assertReceivedOn($sent, is_string($received) ? $received : null);
            $data['received_on'] = $received;
        } else {
            unset($data['received_on']);
        }

        $work->update($data);

        return $this->loadRelations($work->refresh());
    }

    public function transition(LabWork $work, LabWorkStatus $target, ?string $receivedOn): LabWork
    {
        if (! $work->status->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'status' => __('lab.status_not_allowed'),
            ]);
        }

        if ($target === LabWorkStatus::RECEIVED) {
            $received = ($receivedOn === null || $receivedOn === '')
                ? LabWorkTiming::todayDateString()
                : $receivedOn;
            $this->assertReceivedOn($work->sent_on->format('Y-m-d'), $received);
            $work->update([
                'status' => $target,
                'received_on' => $received,
            ]);
        } elseif ($target === LabWorkStatus::AT_LAB) {
            $work->update([
                'status' => $target,
                'received_on' => null,
            ]);
        } else {
            $work->update([
                'status' => $target,
            ]);
        }

        return $this->loadRelations($work->refresh());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = LabWork::query()->with([
            'patient:id,full_name',
            'lab:id,name,is_active',
        ]);

        if (! empty($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (! empty($filters['lab_id'])) {
            $query->where('lab_id', $filters['lab_id']);
        }

        if (! empty($filters['search'])) {
            $pattern = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->whereHas('patient', function (Builder $patient) use ($pattern) {
                $patient->where(function (Builder $inner) use ($pattern) {
                    $inner->where('full_name', 'ilike', $pattern)
                        ->orWhere('phone', 'ilike', $pattern);
                });
            });
        }

        $timing = $filters['timing'] ?? null;
        $tab = $filters['tab'] ?? LabWorkListTab::AT_LAB;
        $today = LabWorkTiming::todayDateString();
        $weekEnd = LabWorkTiming::endOfWeekDateString();

        if ($timing instanceof LabWorkTimingFilter) {
            $query->where('status', LabWorkStatus::AT_LAB);

            match ($timing) {
                LabWorkTimingFilter::OVERDUE => $query->whereDate('expected_on', '<', $today),
                LabWorkTimingFilter::DUE_TODAY => $query->whereDate('expected_on', '=', $today),
                LabWorkTimingFilter::DUE_THIS_WEEK => $query
                    ->whereDate('expected_on', '>=', $today)
                    ->whereDate('expected_on', '<=', $weekEnd),
            };
        } elseif ($tab === LabWorkListTab::RECEIVED) {
            $query->where('status', LabWorkStatus::RECEIVED);
        } elseif ($tab !== LabWorkListTab::ALL) {
            $query->where('status', LabWorkStatus::AT_LAB);
        }

        $dateColumn = $this->dateColumn($filters);

        if (! empty($filters['date_from'])) {
            $query->whereDate($dateColumn, '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate($dateColumn, '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applySort(Builder $query, array $filters): void
    {
        $tab = $filters['tab'] ?? LabWorkListTab::AT_LAB;

        if (($filters['timing'] ?? null) instanceof LabWorkTimingFilter || $tab === LabWorkListTab::AT_LAB) {
            $query->orderBy('expected_on')->orderBy('sent_on');

            return;
        }

        if ($tab === LabWorkListTab::RECEIVED) {
            $query->orderByDesc('received_on')->orderByDesc('sent_on');

            return;
        }

        $query->orderByDesc('sent_on');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function dateColumn(array $filters): string
    {
        if (($filters['timing'] ?? null) instanceof LabWorkTimingFilter) {
            return 'expected_on';
        }

        $tab = $filters['tab'] ?? LabWorkListTab::AT_LAB;

        return match ($tab) {
            LabWorkListTab::RECEIVED => 'received_on',
            LabWorkListTab::ALL => 'sent_on',
            default => 'expected_on',
        };
    }

    private function labOrFail(string $labId): Lab
    {
        $lab = Lab::query()->whereKey($labId)->first();

        if (! $lab) {
            throw ValidationException::withMessages([
                'lab_id' => __('lab.invalid_lab'),
            ]);
        }

        return $lab;
    }

    private function defaultExpected(string $sentOn, Lab $lab): string
    {
        if ($lab->default_turnaround_days === null) {
            return $sentOn;
        }

        return LabWorkTiming::parseDate($sentOn)
            ->addDays($lab->default_turnaround_days)
            ->toDateString();
    }

    private function assertExpectedOnOrAfterSent(string $sentOn, string $expectedOn): void
    {
        if ($expectedOn < $sentOn) {
            throw ValidationException::withMessages([
                'expected_on' => __('lab.expected_before_sent'),
            ]);
        }
    }

    private function assertReceivedOn(string $sentOn, ?string $receivedOn): void
    {
        if ($receivedOn === null || $receivedOn === '') {
            throw ValidationException::withMessages([
                'received_on' => __('lab.received_required'),
            ]);
        }

        if ($receivedOn < $sentOn) {
            throw ValidationException::withMessages([
                'received_on' => __('lab.received_before_sent'),
            ]);
        }

        if ($receivedOn > LabWorkTiming::todayDateString()) {
            throw ValidationException::withMessages([
                'received_on' => __('lab.received_in_future'),
            ]);
        }
    }

    private function blankToNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function loadRelations(LabWork $work): LabWork
    {
        return $work->load([
            'patient:id,full_name',
            'lab:id,name,is_active',
        ]);
    }
}
