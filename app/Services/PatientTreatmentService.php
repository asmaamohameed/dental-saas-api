<?php

namespace App\Services;

use App\Enums\PatientTreatmentStatus;
use App\Enums\PatientTreatmentVisitStatus;
use App\Enums\ToothCondition;
use App\Enums\ToothTreatmentStatus;
use App\Enums\InvoiceStatus;
use App\Models\Component;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentVisit;
use App\Models\ToothRecord;
use App\Models\TreatmentTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientTreatmentService
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PatientTreatment::query()
            ->with(['patient', 'template', 'doctor', 'visits.components', 'visits.dentist', 'visits.appointment']);

        if (!empty($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['doctor_id'])) {
            $query->where('doctor_id', $filters['doctor_id']);
        }

        return $query->latest()->paginate($perPage);
    }

    public function createFromTemplate(array $data, string $userId): PatientTreatment
    {
        return DB::transaction(function () use ($data, $userId) {
            /** @var TreatmentTemplate $template */
            $template = TreatmentTemplate::query()
                ->with(['visits.components.component'])
                ->findOrFail($data['treatment_template_id']);

            $templateVisitCount = count($template->visits);
            $totalVisits = isset($data['total_visits']) ? (int) $data['total_visits'] : max(1, $templateVisitCount);

            $treatment = PatientTreatment::create([
                'patient_id' => $data['patient_id'],
                'treatment_template_id' => $template->id,
                'doctor_id' => $data['doctor_id'] ?? null,
                'tooth_number' => $data['tooth_number'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'status' => $data['status'] ?? PatientTreatmentStatus::PLANNED,
                'priority' => $data['priority'] ?? 'routine',
                'total_visits' => $totalVisits,
                'actual_price' => $data['actual_price'] ?? $template->default_price,
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $toothCondition = $data['tooth_condition'] ?? null;

            if ($templateVisitCount > 0) {
                foreach ($template->visits as $templateVisit) {
                    $visit = $treatment->visits()->create([
                        'visit_order' => $templateVisit->visit_order,
                        'name' => $templateVisit->name_en ?: $templateVisit->name_ar,
                        'description' => $templateVisit->description,
                        'status' => PatientTreatmentVisitStatus::PLANNED,
                        'dentist_id' => $data['doctor_id'] ?? null,
                    ]);

                    foreach ($templateVisit->components as $templateComponent) {
                        $component = $templateComponent->component;

                        $visit->components()->create([
                            'component_id' => $component?->id,
                            'name' => $component?->name_en ?: $component?->name_ar ?: 'Component',
                            'unit_price' => $templateComponent->unit_price,
                            'quantity' => $templateComponent->quantity,
                            'free_quantity' => $templateComponent->free_quantity,
                            'unit' => $component?->unit ?: 'piece',
                            'inventory_item_id' => $component?->inventory_item_id,
                        ]);
                    }
                }
            } else {
                for ($i = 1; $i <= $totalVisits; $i++) {
                    $treatment->visits()->create([
                        'visit_order' => $i,
                        'name' => "Visit {$i}",
                        'description' => null,
                        'status' => PatientTreatmentVisitStatus::PLANNED,
                        'dentist_id' => $data['doctor_id'] ?? null,
                    ]);
                }
            }

            $this->syncToothRecord($treatment->fresh(['template']), $userId, $toothCondition);

            $treatment = $treatment->load(['patient', 'template', 'doctor', 'visits.components', 'visits.dentist', 'visits.appointment']);

            $this->invoiceService->createInitialInvoiceForTreatment($treatment, $userId);

            return $treatment->fresh(['patient', 'template', 'doctor', 'visits.components', 'visits.dentist', 'visits.appointment']);
        });
    }

    public function delete(PatientTreatment $treatment): void
    {
        DB::transaction(function () use ($treatment) {
            $this->assertTreatmentCanBeDeleted($treatment);
            $this->invoiceService->removeUnpaidInvoicesForTreatment($treatment);
            $treatment->delete();
        });
    }

    public function update(PatientTreatment $treatment, array $data, string $userId): PatientTreatment
    {
        return DB::transaction(function () use ($treatment, $data, $userId) {
            $toothCondition = $data['tooth_condition'] ?? null;
            unset($data['tooth_condition']);

            $previousTooth = $treatment->tooth_number;
            $previousStatus = $treatment->status;

            $newStatus = $data['status'] ?? null;
            $cancelling = $newStatus === PatientTreatmentStatus::CANCELLED
                || $newStatus === PatientTreatmentStatus::CANCELLED->value;

            if ($cancelling) {
                $this->assertTreatmentBillingLocked($treatment);
            }

            $treatment->update($data);

            if ($cancelling) {
                $treatment->visits()
                    ->whereNot('status', PatientTreatmentVisitStatus::COMPLETED)
                    ->update(['status' => PatientTreatmentVisitStatus::CANCELLED]);
            }

            $shouldSync = ($data['tooth_number'] ?? $previousTooth)
                && (
                    array_key_exists('tooth_number', $data)
                    || array_key_exists('status', $data)
                    || $toothCondition
                    || (string) $previousTooth !== (string) $treatment->tooth_number
                    || $previousStatus !== $treatment->status
                );

            if ($shouldSync) {
                $this->syncToothRecord($treatment->fresh(['template']), $userId, $toothCondition);
            }

            return $treatment->load(['patient', 'template', 'doctor', 'visits.components', 'visits.dentist', 'visits.appointment']);
        });
    }

    /**
     * @return array{visit: PatientTreatmentVisit, low_stock_warnings: array<int, array<string, mixed>>}
     */
    public function updateVisit(PatientTreatmentVisit $visit, array $data, string $userId): array
    {
        return DB::transaction(function () use ($visit, $data, $userId) {
            $visit->load(['components', 'treatment.visits']);
            $previousStatus = $this->visitStatusValue($visit);
            $newStatus = $data['status'] instanceof PatientTreatmentVisitStatus
                ? $data['status']->value
                : (string) $data['status'];

            $this->assertVisitUpdateOrder($visit, $previousStatus, $newStatus);

            $lowStockWarnings = [];

            if ($previousStatus !== PatientTreatmentVisitStatus::COMPLETED->value && $newStatus === PatientTreatmentVisitStatus::COMPLETED->value) {
                $lowStockWarnings = $this->deductVisitComponents($visit);
            }

            if ($previousStatus === PatientTreatmentVisitStatus::COMPLETED->value && $newStatus !== PatientTreatmentVisitStatus::COMPLETED->value) {
                $this->restoreVisitComponents($visit);
            }

            $visit->update($data);

            /** @var PatientTreatment $treatment */
            $treatment = $visit->treatment()->first();
            $this->recomputeTreatmentStatus($treatment, $userId);

            return [
                'visit' => $visit->fresh(['components', 'appointments', 'dentist', 'appointment']),
                'low_stock_warnings' => $lowStockWarnings,
            ];
        });
    }

    public function getInvoiceSummary(PatientTreatment $treatment): array
    {
        $treatment->load(['visits', 'template']);

        $items = InvoiceItem::query()
            ->where('patient_treatment_id', $treatment->id)
            ->with(['invoice.payments', 'patientTreatmentVisit'])
            ->get();

        $invoiceIds = $items->pluck('invoice_id')->unique();
        $invoices = Invoice::query()->whereIn('id', $invoiceIds)->with('payments')->get()->keyBy('id');

        $totalBilled = (float) $items->sum(fn ($item) => (float) $item->price * (int) $item->quantity);

        $totalPaid = 0.0;
        foreach ($invoiceIds as $invoiceId) {
            $invoice = $invoices->get($invoiceId);
            if ($invoice) {
                $totalPaid += (float) $invoice->payments->sum('amount');
            }
        }

        $openInvoiceExists = Invoice::query()
            ->where('patient_id', $treatment->patient_id)
            ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::PARTIAL])
            ->whereHas('items', fn ($q) => $q->where('patient_treatment_id', $treatment->id))
            ->exists();

        $visits = $treatment->visits->map(function (PatientTreatmentVisit $visit) use ($items, $invoices) {
            $visitItems = $items->where('patient_treatment_visit_id', $visit->id);
            $item = $visitItems->first();
            $invoice = $item ? $invoices->get($item->invoice_id) : null;

            return [
                'visit_id' => $visit->id,
                'visit_order' => $visit->visit_order,
                'name' => $visit->name,
                'status' => $this->visitStatusValue($visit),
                'invoice_item' => $item ? [
                    'id' => $item->id,
                    'invoice_id' => $item->invoice_id,
                    'price' => (float) $item->price,
                    'amount' => (float) $item->price * (int) $item->quantity,
                    'invoice_status' => $invoice?->status?->value ?? (string) $invoice?->status,
                ] : null,
            ];
        })->values();

        $primaryInvoice = Invoice::query()
            ->whereHas('items', fn ($q) => $q->where('patient_treatment_id', $treatment->id))
            ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::PARTIAL])
            ->latest()
            ->first();

        if (!$primaryInvoice) {
            $primaryInvoice = Invoice::query()
                ->whereHas('items', fn ($q) => $q->where('patient_treatment_id', $treatment->id))
                ->latest()
                ->first();
        }

        return [
            'patient_treatment_id' => $treatment->id,
            'invoice_id' => $primaryInvoice?->id,
            'total_billed' => $totalBilled,
            'total_paid' => $totalPaid,
            'outstanding' => max(0, $totalBilled - $totalPaid),
            'has_open_invoice' => $openInvoiceExists,
            'visits' => $visits,
        ];
    }

    public function recomputeTreatmentStatus(PatientTreatment $treatment, string $userId): void
    {
        if ($treatment->status === PatientTreatmentStatus::CANCELLED) {
            return;
        }

        $visits = $treatment->visits()->get();
        $totalVisits = $visits->count();

        if ($totalVisits === 0) {
            return;
        }

        $completedVisits = 0;
        $cancelledVisits = 0;
        $pendingVisits = 0;

        foreach ($visits as $visit) {
            $status = $this->visitStatusValue($visit);
            if ($status === PatientTreatmentVisitStatus::COMPLETED->value) {
                $completedVisits++;
            } elseif ($status === PatientTreatmentVisitStatus::CANCELLED->value) {
                $cancelledVisits++;
            } elseif (in_array($status, [
                PatientTreatmentVisitStatus::PLANNED->value,
                PatientTreatmentVisitStatus::SCHEDULED->value,
                PatientTreatmentVisitStatus::IN_PROGRESS->value,
            ], true)) {
                $pendingVisits++;
            }
        }

        if ($cancelledVisits === $totalVisits) {
            $treatment->status = PatientTreatmentStatus::CANCELLED;
            $treatment->completed_at = null;
        } elseif ($pendingVisits === 0 && $completedVisits > 0 && ($completedVisits + $cancelledVisits) === $totalVisits) {
            $treatment->status = PatientTreatmentStatus::COMPLETED;
            $treatment->completed_at = now();
        } elseif ($completedVisits === 0) {
            if (in_array($treatment->status, [PatientTreatmentStatus::IN_PROGRESS, PatientTreatmentStatus::COMPLETED], true)) {
                $treatment->status = PatientTreatmentStatus::PLANNED;
                $treatment->completed_at = null;
            }
        } elseif ($completedVisits > 0 && $pendingVisits > 0) {
            $treatment->status = PatientTreatmentStatus::IN_PROGRESS;
            if (!$treatment->started_at) {
                $treatment->started_at = now();
            }
            $treatment->completed_at = null;
        } elseif ($completedVisits === $totalVisits) {
            $treatment->status = PatientTreatmentStatus::COMPLETED;
            $treatment->completed_at = now();
        }

        $treatment->total_visits = max($totalVisits, $treatment->total_visits ?: 1);
        $treatment->save();

        $this->syncToothRecord($treatment->fresh(['template']), $userId);
    }

    public function nextForPatient(string $patientId): ?PatientTreatment
    {
        $nonCompleted = PatientTreatment::query()
            ->with(['template', 'doctor', 'visits.components', 'visits.dentist', 'visits.appointment'])
            ->where('patient_id', $patientId)
            ->whereNotIn('status', [PatientTreatmentStatus::COMPLETED, PatientTreatmentStatus::CANCELLED])
            ->get();

        if ($nonCompleted->isEmpty()) {
            return null;
        }

        // Sort treatments by the scheduled_date of their current (first non-completed) visit
        $sorted = $nonCompleted->sortBy(function ($treatment) {
            $currentVisit = $treatment->visits->first(function ($v) {
                $s = $v->status instanceof PatientTreatmentVisitStatus ? $v->status->value : (string) $v->status;
                return $s !== 'completed';
            });

            if (!$currentVisit) {
                return 9999999999;
            }

            if ($currentVisit->scheduled_date) {
                return $currentVisit->scheduled_date->timestamp;
            }

            // Fallback: priority score
            $pScore = match ($treatment->priority) {
                'high' => 10,
                'medium' => 20,
                'low' => 30,
                default => 40,
            };

            return 1000000000 + $pScore + $currentVisit->visit_order;
        });

        return $sorted->first();
    }

    private function syncToothRecord(PatientTreatment $treatment, string $userId, ?string $condition = null): void
    {
        if (!$treatment->tooth_number) {
            return;
        }

        $latest = ToothRecord::query()
            ->where('patient_id', $treatment->patient_id)
            ->where('tooth_number', $treatment->tooth_number)
            ->latest()
            ->first();

        $treatmentStatusStr = $treatment->status instanceof PatientTreatmentStatus ? $treatment->status->value : (string) $treatment->status;

        $toothStatus = match ($treatmentStatusStr) {
            'in_progress' => ToothTreatmentStatus::IN_PROGRESS,
            'completed' => ToothTreatmentStatus::COMPLETED,
            default => ToothTreatmentStatus::PLANNED,
        };

        $resolvedCondition = $condition
            ?? ($latest?->condition instanceof ToothCondition ? $latest->condition->value : $latest?->condition)
            ?? ToothCondition::DECAYED->value;

        // If treatment was completed and was e.g. filling or root canal, resolve condition appropriately if default
        if ($treatmentStatusStr === 'completed' && !$condition) {
            $tmpl = strtolower($treatment->template?->name_en ?: '');
            if (str_contains($tmpl, 'fill')) {
                $resolvedCondition = ToothCondition::FILLED->value;
            } elseif (str_contains($tmpl, 'root canal')) {
                $resolvedCondition = ToothCondition::ROOT_CANAL->value;
            } elseif (str_contains($tmpl, 'crown')) {
                $resolvedCondition = ToothCondition::CROWN->value;
            }
        }

        $templateName = $treatment->template?->name_en ?: $treatment->template?->name_ar;
        $statusNote = $treatmentStatusStr === 'cancelled' ? 'Treatment Cancelled' : null;
        $notes = collect([$templateName, $treatment->diagnosis, $statusNote])->filter()->implode(' — ') ?: null;

        ToothRecord::create([
            'patient_id' => $treatment->patient_id,
            'appointment_id' => null,
            'recorded_by' => $userId,
            'tooth_number' => $treatment->tooth_number,
            'condition' => $resolvedCondition,
            'treatment_status' => $toothStatus,
            'notes' => $notes,
        ]);
    }

    private function visitStatusValue(PatientTreatmentVisit $visit): string
    {
        return $visit->status instanceof PatientTreatmentVisitStatus
            ? $visit->status->value
            : (string) $visit->status;
    }

    private function assertVisitUpdateOrder(PatientTreatmentVisit $visit, string $previousStatus, string $newStatus): void
    {
        /** @var PatientTreatment $treatment */
        $treatment = $visit->treatment;
        $visits = $treatment->visits->sortBy('visit_order')->values();

        $current = $visits->first(function (PatientTreatmentVisit $v) {
            $status = $this->visitStatusValue($v);

            return !in_array($status, [
                PatientTreatmentVisitStatus::COMPLETED->value,
                PatientTreatmentVisitStatus::CANCELLED->value,
            ], true);
        });

        $isUndoLatestComplete = $previousStatus === PatientTreatmentVisitStatus::COMPLETED->value
            && $newStatus !== PatientTreatmentVisitStatus::COMPLETED->value
            && $this->isLatestCompletedVisit($visit, $visits);

        if ($current && $current->id !== $visit->id && !$isUndoLatestComplete) {
            throw ValidationException::withMessages([
                'status' => "Update visits in order. Work on Visit {$current->visit_order} first.",
            ]);
        }

        if ($newStatus === PatientTreatmentVisitStatus::COMPLETED->value) {
            foreach ($visits as $priorVisit) {
                if ($priorVisit->visit_order >= $visit->visit_order) {
                    break;
                }

                $priorStatus = $this->visitStatusValue($priorVisit);
                if (!in_array($priorStatus, [
                    PatientTreatmentVisitStatus::COMPLETED->value,
                    PatientTreatmentVisitStatus::CANCELLED->value,
                ], true)) {
                    throw ValidationException::withMessages([
                        'status' => "Visit {$priorVisit->visit_order} must be completed or cancelled before completing Visit {$visit->visit_order}.",
                    ]);
                }
            }
        }
    }

    private function isLatestCompletedVisit(PatientTreatmentVisit $visit, $visits): bool
    {
        if ($this->visitStatusValue($visit) !== PatientTreatmentVisitStatus::COMPLETED->value) {
            return false;
        }

        $laterCompletedExists = $visits->contains(function (PatientTreatmentVisit $v) use ($visit) {
            return $v->visit_order > $visit->visit_order
                && $this->visitStatusValue($v) === PatientTreatmentVisitStatus::COMPLETED->value;
        });

        if ($laterCompletedExists) {
            return false;
        }

        return true;
    }

    private function billableComponentQuantity(float $quantity, float $freeQuantity): string
    {
        $deduct = max(0, $quantity - $freeQuantity);

        return number_format($deduct, 2, '.', '');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deductVisitComponents(PatientTreatmentVisit $visit): array
    {
        $warnings = [];
        $visitComponents = $visit->components()->get();

        foreach ($visitComponents as $ptc) {
            if (!$ptc->component_id) {
                continue;
            }

            $deduct = $this->billableComponentQuantity((float) $ptc->quantity, (float) $ptc->free_quantity);
            if (bccomp($deduct, '0', 2) <= 0) {
                continue;
            }

            /** @var Component|null $component */
            $component = Component::query()->whereKey($ptc->component_id)->lockForUpdate()->first();
            if (!$component) {
                throw ValidationException::withMessages([
                    'status' => 'One or more linked components could not be found for stock deduction.',
                ]);
            }

            $available = bcadd((string) ($component->current_quantity ?? 0), '0', 2);
            if (bccomp($available, $deduct, 2) === -1) {
                throw ValidationException::withMessages([
                    'status' => "Insufficient stock for {$component->name_en}. Available: {$available} {$component->unit}, Required: {$deduct} {$component->unit}.",
                ]);
            }

            $newQuantity = bcsub($available, $deduct, 2);
            $component->update(['current_quantity' => $newQuantity]);
            $component->refresh();

            if ($component->is_low_stock) {
                $warnings[] = [
                    'component_id' => $component->id,
                    'name' => $component->name_en ?: $component->name_ar,
                    'current_quantity' => (float) $component->current_quantity,
                    'unit' => $component->unit,
                ];
            }
        }

        return $warnings;
    }

    private function restoreVisitComponents(PatientTreatmentVisit $visit): void
    {
        $visit->loadMissing('components');

        foreach ($visit->components as $ptc) {
            if (!$ptc->component_id) {
                continue;
            }

            $deduct = $this->billableComponentQuantity((float) $ptc->quantity, (float) $ptc->free_quantity);
            if (bccomp($deduct, '0', 2) <= 0) {
                continue;
            }

            /** @var Component|null $component */
            $component = Component::query()->whereKey($ptc->component_id)->lockForUpdate()->first();
            if (!$component) {
                continue;
            }

            $restored = bcadd((string) ($component->current_quantity ?? 0), $deduct, 2);
            $component->update(['current_quantity' => $restored]);
        }
    }

    private function assertTreatmentBillingLocked(PatientTreatment $treatment): void
    {
        $hasPaidOrPartialInvoice = InvoiceItem::query()
            ->where('patient_treatment_id', $treatment->id)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', [InvoiceStatus::PAID, InvoiceStatus::PARTIAL]))
            ->exists();

        if ($hasPaidOrPartialInvoice) {
            throw ValidationException::withMessages([
                'status' => 'Cannot cancel a treatment with a partially or fully paid invoice. Please issue a refund first.',
            ]);
        }
    }

    private function assertTreatmentCanBeDeleted(PatientTreatment $treatment): void
    {
        $hasPaidOrPartialInvoice = InvoiceItem::query()
            ->where('patient_treatment_id', $treatment->id)
            ->whereHas('invoice', fn ($q) => $q->whereIn('status', [InvoiceStatus::PAID, InvoiceStatus::PARTIAL]))
            ->exists();

        if ($hasPaidOrPartialInvoice) {
            throw ValidationException::withMessages([
                'treatment' => 'Cannot delete a treatment with a partially or fully paid invoice.',
            ]);
        }

        $hasPaymentsOnLinkedInvoice = InvoiceItem::query()
            ->where('patient_treatment_id', $treatment->id)
            ->whereHas('invoice', fn ($q) => $q->whereHas('payments'))
            ->exists();

        if ($hasPaymentsOnLinkedInvoice) {
            throw ValidationException::withMessages([
                'treatment' => 'Cannot delete a treatment while its invoice has recorded payments.',
            ]);
        }
    }
}
