<?php

namespace App\Services;

use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\SessionStepStatus;
use App\Enums\TreatmentSessionStatus;
use App\Enums\TreatmentType;
use App\Models\Component;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\Payment;
use App\Models\TreatmentSession;
use App\Models\TreatmentSessionStep;
use App\Models\TreatmentTemplate;
use App\Models\TreatmentTemplateStep;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientTreatmentService
{
    public function __construct(private readonly InvoiceService $invoiceService) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = PatientTreatment::query()->with($this->listRelations());

        if (! empty($filters['patient_id'])) {
            $query->where('patient_id', $filters['patient_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('clinical_status', $filters['status']);
        }

        if (! empty($filters['clinical_status'])) {
            $query->where('clinical_status', $filters['clinical_status']);
        }

        if (! empty($filters['dentist_id'])) {
            $query->where('dentist_id', $filters['dentist_id']);
        }

        if (! empty($filters['treatment_plan_id'])) {
            $query->where('treatment_plan_id', $filters['treatment_plan_id']);
        }

        return $query->latest()->paginate($perPage);
    }

    public function createFromTemplate(array $data, string $userId): PatientTreatment
    {
        return DB::transaction(function () use ($data, $userId) {
            /** @var TreatmentTemplate $template */
            $template = TreatmentTemplate::query()
                ->with(['steps.components.component'])
                ->findOrFail($data['treatment_template_id']);

            $toothNumbers = $this->normalizeToothNumbers($data['tooth_numbers'] ?? []);

            $treatment = PatientTreatment::create([
                'patient_id' => $data['patient_id'],
                'treatment_plan_id' => $data['treatment_plan_id'] ?? null,
                'treatment_template_id' => $template->id,
                'treatment_template_version' => $template->version,
                'agreed_price' => $data['agreed_price'] ?? $template->default_price,
                'clinical_status' => $data['clinical_status'] ?? PatientTreatmentStatus::PLANNED,
                'financial_status' => FinancialStatus::UNPAID,
                'consent_status' => $data['consent_status'] ?? ConsentStatus::NOT_REQUIRED,
                'consent_document_ref' => $data['consent_document_ref'] ?? null,
                'parent_treatment_id' => $data['parent_treatment_id'] ?? null,
                'treatment_type' => $data['treatment_type'] ?? TreatmentType::ORIGINAL,
                'dentist_id' => $data['dentist_id'] ?? $data['doctor_id'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'priority' => $data['priority'] ?? 'routine',
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncTeeth($treatment, $toothNumbers);

            $treatment = $this->freshTreatment($treatment);
            $this->invoiceService->createInitialInvoiceForTreatment($treatment, $userId);
            $this->invoiceService->recomputeFinancialStatus($treatment);

            return $this->freshTreatment($treatment);
        });
    }

    public function update(PatientTreatment $treatment, array $data, string $userId): PatientTreatment
    {
        return DB::transaction(function () use ($treatment, $data, $userId) {
            $toothNumbers = array_key_exists('tooth_numbers', $data)
                ? $this->normalizeToothNumbers($data['tooth_numbers'])
                : null;
            unset($data['tooth_numbers'], $data['tooth_number'], $data['status'], $data['doctor_id'], $data['actual_price']);

            if (isset($data['clinical_status'])) {
                $this->applyClinicalStatus($treatment, $data);
            }

            $allowed = collect($data)->only([
                'treatment_plan_id',
                'dentist_id',
                'diagnosis',
                'priority',
                'notes',
                'consent_status',
                'consent_document_ref',
                'agreed_price',
            ])->all();

            if ($allowed !== []) {
                if (array_key_exists('agreed_price', $allowed) && $treatment->sessions()->exists()) {
                    throw ValidationException::withMessages([
                        'agreed_price' => 'Agreed price is locked after the first session is recorded.',
                    ]);
                }

                $treatment->update($allowed);
            }

            if ($toothNumbers !== null) {
                $this->syncTeeth($treatment, $toothNumbers);
            }

            unset($userId);

            return $this->freshTreatment($treatment);
        });
    }

    public function updateClinicalStatus(PatientTreatment $treatment, array $data, string $userId): PatientTreatment
    {
        return DB::transaction(function () use ($treatment, $data, $userId) {
            $this->applyClinicalStatus($treatment, $data);
            unset($userId);

            return $this->freshTreatment($treatment);
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

    public function retreat(PatientTreatment $treatment, array $data, string $userId): PatientTreatment
    {
        return DB::transaction(function () use ($treatment, $data, $userId) {
            $status = $this->clinical($treatment);

            if (! in_array($status, [PatientTreatmentStatus::FAILED, PatientTreatmentStatus::COMPLETED], true)) {
                $treatment->clinical_status = PatientTreatmentStatus::FAILED;
                $treatment->save();
            } elseif ($status === PatientTreatmentStatus::COMPLETED) {
                $treatment->clinical_status = PatientTreatmentStatus::FAILED;
                $treatment->save();
            }

            $payload = [
                'patient_id' => $treatment->patient_id,
                'treatment_plan_id' => $data['treatment_plan_id'] ?? $treatment->treatment_plan_id,
                'treatment_template_id' => $data['treatment_template_id'] ?? $treatment->treatment_template_id,
                'agreed_price' => $data['agreed_price']
                    ?? ($treatment->template !== null ? $treatment->template->default_price : null)
                    ?? $treatment->agreed_price,
                'dentist_id' => $data['dentist_id'] ?? $treatment->dentist_id,
                'diagnosis' => $data['diagnosis'] ?? $treatment->diagnosis,
                'priority' => $data['priority'] ?? $treatment->priority->value,
                'notes' => $data['notes'] ?? null,
                'consent_status' => $data['consent_status'] ?? $treatment->consent_status->value,
                'parent_treatment_id' => $treatment->id,
                'treatment_type' => TreatmentType::RETREATMENT->value,
                'tooth_numbers' => $data['tooth_numbers'] ?? $treatment->teeth()->pluck('tooth_number')->all(),
            ];

            return $this->createFromTemplate($payload, $userId);
        });
    }

    /**
     * @return array{session: TreatmentSession, low_stock_warnings: array<int, array<string, mixed>>}
     */
    public function createSession(PatientTreatment $treatment, array $data, string $userId): array
    {
        return DB::transaction(function () use ($treatment, $data, $userId) {
            $clinical = $this->clinical($treatment);
            if ($clinical->isTerminal()) {
                throw ValidationException::withMessages([
                    'session' => 'Cannot add a session to a cancelled or failed treatment.',
                ]);
            }

            $session = $treatment->sessions()->create([
                'appointment_id' => $data['appointment_id'] ?? null,
                'dentist_id' => $data['dentist_id'] ?? $treatment->dentist_id,
                'session_date' => $data['session_date'] ?? now(),
                'status' => $data['status'] ?? TreatmentSessionStatus::SCHEDULED,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['steps'] ?? [] as $stepData) {
                $this->recordSessionStep($session, $stepData, false);
            }

            $warnings = $this->afterSessionMutation($session->fresh(['steps', 'components', 'treatment.template.steps', 'treatment.sessions.steps']), $userId);

            return [
                'session' => $session->fresh(['steps.templateStep', 'dentist', 'appointment', 'components']),
                'low_stock_warnings' => $warnings,
            ];
        });
    }

    /**
     * @return array{session: TreatmentSession, low_stock_warnings: array<int, array<string, mixed>>}
     */
    public function updateSession(TreatmentSession $session, array $data, string $userId): array
    {
        return DB::transaction(function () use ($session, $data, $userId) {
            $session->load(['steps', 'components', 'treatment.template.steps', 'treatment.sessions.steps']);
            $previous = $this->sessionStatus($session);

            $session->update(collect($data)->only([
                'appointment_id',
                'dentist_id',
                'session_date',
                'status',
                'notes',
            ])->all());

            $session->refresh();
            $warnings = $this->syncSessionStock($session, $previous, $this->sessionStatus($session));
            $this->recomputeClinicalStatus($session->treatment, $userId);

            return [
                'session' => $session->fresh(['steps.templateStep', 'dentist', 'appointment', 'components']),
                'low_stock_warnings' => $warnings,
            ];
        });
    }

    public function addSessionStep(TreatmentSession $session, array $data, string $userId): TreatmentSessionStep
    {
        return DB::transaction(function () use ($session, $data, $userId) {
            $step = $this->recordSessionStep($session, $data, true);
            $this->afterSessionMutation($session->fresh(['steps', 'components', 'treatment.template.steps', 'treatment.sessions.steps']), $userId);

            return $step->fresh(['templateStep']);
        });
    }

    public function updateSessionStep(TreatmentSessionStep $step, array $data, string $userId): TreatmentSessionStep
    {
        return DB::transaction(function () use ($step, $data, $userId) {
            $step->update(collect($data)->only(['status', 'notes', 'custom_step_name', 'custom_step_description'])->all());
            $session = $step->session()->with(['steps', 'components', 'treatment.template.steps', 'treatment.sessions.steps'])->first();
            $this->afterSessionMutation($session, $userId);

            return $step->fresh(['templateStep']);
        });
    }

    public function deleteSessionStep(TreatmentSessionStep $step, string $userId): void
    {
        DB::transaction(function () use ($step, $userId) {
            $session = $step->session()->with(['steps', 'components', 'treatment.template.steps', 'treatment.sessions.steps'])->first();
            $step->delete();
            $this->afterSessionMutation($session->fresh(['steps', 'components', 'treatment.template.steps', 'treatment.sessions.steps']), $userId);
        });
    }

    public function syncSessionsFromAppointment(string $appointmentId, TreatmentSessionStatus $status, string $userId): void
    {
        $sessions = TreatmentSession::query()
            ->where('appointment_id', $appointmentId)
            ->where('status', TreatmentSessionStatus::SCHEDULED)
            ->with(['steps', 'components', 'treatment.template.steps', 'treatment.sessions.steps'])
            ->get();

        foreach ($sessions as $session) {
            $previous = $this->sessionStatus($session);
            $session->update(['status' => $status]);
            $session->refresh();
            $this->syncSessionStock($session, $previous, $status);
            $this->recomputeClinicalStatus($session->treatment, $userId);
        }
    }

    public function attachTreatmentsToAppointment(string $appointmentId, array $treatmentIds, string $dentistId, string $sessionDate, string $userId): void
    {
        foreach ($treatmentIds as $treatmentId) {
            $treatment = PatientTreatment::query()->find($treatmentId);
            if (! $treatment) {
                continue;
            }

            $exists = $treatment->sessions()
                ->where('appointment_id', $appointmentId)
                ->exists();

            if ($exists) {
                continue;
            }

            $this->createSession($treatment, [
                'appointment_id' => $appointmentId,
                'dentist_id' => $dentistId,
                'session_date' => $sessionDate,
                'status' => TreatmentSessionStatus::SCHEDULED->value,
            ], $userId);
        }
    }

    public function getInvoiceSummary(PatientTreatment $treatment): array
    {
        $items = InvoiceItem::query()
            ->where('patient_treatment_id', $treatment->id)
            ->with(['invoice.payments'])
            ->get();

        $invoiceIds = $items->pluck('invoice_id')->unique();
        $invoices = Invoice::query()->whereIn('id', $invoiceIds)->with('payments')->get()->keyBy('id');

        $totalBilled = (float) $items->sum(fn ($item) => (float) $item->price * (int) $item->quantity);
        $totalPaid = $this->allocatedPaidAmount($items, $invoices);

        $openInvoiceExists = Invoice::query()
            ->where('patient_id', $treatment->patient_id)
            ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::PARTIAL])
            ->whereHas('items', fn ($q) => $q->where('patient_treatment_id', $treatment->id))
            ->exists();

        $primaryInvoice = Invoice::query()
            ->whereHas('items', fn ($q) => $q->where('patient_treatment_id', $treatment->id))
            ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::PARTIAL])
            ->latest()
            ->first();

        if (! $primaryInvoice) {
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
            'financial_status' => $treatment->financial_status,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function toothStatusMap(string $patientId): array
    {
        $treatments = PatientTreatment::query()
            ->with('teeth')
            ->where('patient_id', $patientId)
            ->where('clinical_status', '!=', PatientTreatmentStatus::CANCELLED)
            ->get();

        $byTooth = [];

        foreach ($treatments as $treatment) {
            foreach ($treatment->teeth as $tooth) {
                $byTooth[(string) $tooth->tooth_number][] = $this->clinical($treatment);
            }
        }

        $map = [];
        foreach ($byTooth as $number => $statuses) {
            $map[$number] = $this->priorityColor($statuses);
        }

        return $map;
    }

    public function nextForPatient(string $patientId): ?PatientTreatment
    {
        $open = PatientTreatment::query()
            ->with($this->listRelations())
            ->where('patient_id', $patientId)
            ->where('clinical_status', PatientTreatmentStatus::IN_PROGRESS)
            ->get();

        if ($open->isEmpty()) {
            return null;
        }

        return $open->sortBy(function (PatientTreatment $treatment) {
            if ($treatment->started_at !== null) {
                return $treatment->started_at->timestamp;
            }

            return $treatment->created_at->timestamp;
        })->first();
    }

    public function recomputeClinicalStatus(PatientTreatment $treatment, string $userId): void
    {
        $treatment->load(['template.steps', 'sessions.steps']);
        $clinical = $this->clinical($treatment);

        if (in_array($clinical, [PatientTreatmentStatus::CANCELLED, PatientTreatmentStatus::FAILED, PatientTreatmentStatus::ON_HOLD], true)) {
            return;
        }

        $hasCompletedSession = $treatment->sessions->contains(
            fn (TreatmentSession $session) => $this->sessionStatus($session) === TreatmentSessionStatus::COMPLETED
        );
        $hasDoneStep = $treatment->sessions->flatMap->steps->contains(
            fn (TreatmentSessionStep $step) => $this->stepStatus($step) === SessionStepStatus::DONE
        );

        if ($clinical === PatientTreatmentStatus::PLANNED && ($hasCompletedSession || $hasDoneStep)) {
            $treatment->clinical_status = PatientTreatmentStatus::IN_PROGRESS;
            $treatment->started_at ??= now();
            $treatment->completed_at = null;
            $treatment->save();
            $clinical = PatientTreatmentStatus::IN_PROGRESS;
        }

        if ($clinical === PatientTreatmentStatus::COMPLETED && ! $this->requiredStepsSatisfied($treatment)) {
            $treatment->clinical_status = PatientTreatmentStatus::IN_PROGRESS;
            $treatment->completed_at = null;
            $treatment->save();

            return;
        }

        if ($clinical === PatientTreatmentStatus::IN_PROGRESS && $this->requiredStepsSatisfied($treatment)) {
            $treatment->clinical_status = PatientTreatmentStatus::COMPLETED;
            $treatment->completed_at = now();
            $treatment->save();
        }

        unset($userId);
    }

    private function applyClinicalStatus(PatientTreatment $treatment, array $data): void
    {
        $target = $data['clinical_status'] instanceof PatientTreatmentStatus
            ? $data['clinical_status']
            : PatientTreatmentStatus::from((string) $data['clinical_status']);

        $current = $this->clinical($treatment);

        if ($current === $target) {
            if ($target === PatientTreatmentStatus::CANCELLED && empty($treatment->cancellation_reason) && empty($data['cancellation_reason'])) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'A cancellation reason is required.',
                ]);
            }

            return;
        }

        if (! $current->canTransitionTo($target)) {
            throw ValidationException::withMessages([
                'clinical_status' => "Cannot change clinical status from {$current->value} to {$target->value}.",
            ]);
        }

        if ($target === PatientTreatmentStatus::CANCELLED) {
            if (empty($data['cancellation_reason'])) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'A cancellation reason is required.',
                ]);
            }

            $this->assertTreatmentBillingLocked($treatment);
            $treatment->cancellation_reason = $data['cancellation_reason'];
            $treatment->cancelled_at = now();
            $this->invoiceService->removeUnpaidInvoicesForTreatment($treatment);
        }

        if ($target === PatientTreatmentStatus::COMPLETED) {
            $treatment->completed_at = now();
            $treatment->started_at ??= now();
        }

        if ($target === PatientTreatmentStatus::IN_PROGRESS) {
            $treatment->started_at ??= now();
            $treatment->completed_at = null;
        }

        if ($target === PatientTreatmentStatus::PLANNED) {
            $treatment->completed_at = null;
        }

        $treatment->clinical_status = $target;
        $treatment->save();
    }

    private function recordSessionStep(TreatmentSession $session, array $data, bool $persistOccurrenceCheck): TreatmentSessionStep
    {
        $templateStepId = $data['treatment_template_step_id'] ?? null;
        $status = isset($data['status'])
            ? SessionStepStatus::from((string) ($data['status'] instanceof SessionStepStatus ? $data['status']->value : $data['status']))
            : SessionStepStatus::DONE;

        if (! $templateStepId && empty($data['custom_step_name'])) {
            throw ValidationException::withMessages([
                'custom_step_name' => 'Provide a template step or a custom step name.',
            ]);
        }

        $occurrence = 1;

        if ($templateStepId) {
            /** @var TreatmentTemplateStep $templateStep */
            $templateStep = TreatmentTemplateStep::query()->findOrFail($templateStepId);
            $treatment = $session->treatment()->first();

            if ($templateStep->treatment_template_id !== $treatment->treatment_template_id) {
                throw ValidationException::withMessages([
                    'treatment_template_step_id' => 'This step does not belong to the treatment template version.',
                ]);
            }

            $doneCount = TreatmentSessionStep::query()
                ->whereHas('session', fn ($q) => $q->where('patient_treatment_id', $session->patient_treatment_id))
                ->where('treatment_template_step_id', $templateStepId)
                ->where('status', SessionStepStatus::DONE)
                ->count();

            if ($persistOccurrenceCheck && ! $templateStep->is_repeatable && $status === SessionStepStatus::DONE && $doneCount > 0) {
                throw ValidationException::withMessages([
                    'treatment_template_step_id' => 'This step is not repeatable and has already been recorded as done.',
                ]);
            }

            $occurrence = TreatmentSessionStep::query()
                ->whereHas('session', fn ($q) => $q->where('patient_treatment_id', $session->patient_treatment_id))
                ->where('treatment_template_step_id', $templateStepId)
                ->count() + 1;
        }

        return $session->steps()->create([
            'treatment_template_step_id' => $templateStepId,
            'custom_step_name' => $data['custom_step_name'] ?? null,
            'custom_step_description' => $data['custom_step_description'] ?? null,
            'occurrence_number' => $data['occurrence_number'] ?? $occurrence,
            'status' => $status,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function afterSessionMutation(TreatmentSession $session, string $userId): array
    {
        $previousApplied = (bool) $session->stock_applied;
        $current = $this->sessionStatus($session);
        $warnings = $this->syncSessionStock(
            $session,
            $previousApplied ? TreatmentSessionStatus::COMPLETED : TreatmentSessionStatus::SCHEDULED,
            $current
        );
        $this->recomputeClinicalStatus($session->treatment, $userId);

        return $warnings;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function syncSessionStock(TreatmentSession $session, TreatmentSessionStatus $previous, TreatmentSessionStatus $current): array
    {
        $warnings = [];

        if ($previous !== TreatmentSessionStatus::COMPLETED && $current === TreatmentSessionStatus::COMPLETED && ! $session->stock_applied) {
            $this->copyStepComponentsOntoSession($session);
            $warnings = $this->deductSessionComponents($session);
            $session->update(['stock_applied' => true]);
        }

        if ($previous === TreatmentSessionStatus::COMPLETED && $current !== TreatmentSessionStatus::COMPLETED && $session->stock_applied) {
            $this->restoreSessionComponents($session);
            $session->update(['stock_applied' => false]);
        }

        return $warnings;
    }

    private function copyStepComponentsOntoSession(TreatmentSession $session): void
    {
        if ($session->components()->exists()) {
            return;
        }

        $session->unsetRelation('components');
        $session->load(['steps.templateStep.components.component']);

        foreach ($session->steps as $step) {
            if ($this->stepStatus($step) !== SessionStepStatus::DONE) {
                continue;
            }

            $templateStep = $step->templateStep;
            if ($templateStep === null) {
                continue;
            }

            foreach ($templateStep->components as $templateComponent) {
                $component = $templateComponent->component;
                $session->components()->create([
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
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deductSessionComponents(TreatmentSession $session): array
    {
        $warnings = [];
        $session->unsetRelation('components');
        $session->load('components');

        foreach ($session->components as $ptc) {
            if (! $ptc->component_id) {
                continue;
            }

            $deduct = $this->billableComponentQuantity((float) $ptc->quantity, (float) $ptc->free_quantity);
            if (bccomp($deduct, '0', 2) <= 0) {
                continue;
            }

            /** @var Component|null $component */
            $component = Component::query()->whereKey($ptc->component_id)->lockForUpdate()->first();
            if (! $component) {
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

    private function restoreSessionComponents(TreatmentSession $session): void
    {
        $session->loadMissing('components');

        foreach ($session->components as $ptc) {
            if (! $ptc->component_id) {
                continue;
            }

            $deduct = $this->billableComponentQuantity((float) $ptc->quantity, (float) $ptc->free_quantity);
            if (bccomp($deduct, '0', 2) <= 0) {
                continue;
            }

            /** @var Component|null $component */
            $component = Component::query()->whereKey($ptc->component_id)->lockForUpdate()->first();
            if (! $component) {
                continue;
            }

            $restored = bcadd((string) ($component->current_quantity ?? 0), $deduct, 2);
            $component->update(['current_quantity' => $restored]);
        }
    }

    private function requiredStepsSatisfied(PatientTreatment $treatment): bool
    {
        $required = $treatment->template?->steps?->filter(fn (TreatmentTemplateStep $step) => (bool) $step->is_required) ?? collect();

        if ($required->isEmpty()) {
            return false;
        }

        $doneStepIds = $treatment->sessions
            ->flatMap->steps
            ->filter(fn (TreatmentSessionStep $step) => $this->stepStatus($step) === SessionStepStatus::DONE)
            ->pluck('treatment_template_step_id')
            ->filter()
            ->unique();

        foreach ($required as $step) {
            if (! $doneStepIds->contains($step->id)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<PatientTreatmentStatus>  $statuses
     */
    private function priorityColor(array $statuses): string
    {
        if (collect($statuses)->contains(PatientTreatmentStatus::FAILED)) {
            return 'failed';
        }
        if (collect($statuses)->contains(PatientTreatmentStatus::IN_PROGRESS)) {
            return 'in_progress';
        }
        if (collect($statuses)->contains(PatientTreatmentStatus::ON_HOLD)) {
            return 'in_progress';
        }
        if (collect($statuses)->contains(PatientTreatmentStatus::PLANNED)) {
            return 'planned';
        }
        if ($statuses !== [] && collect($statuses)->every(fn (PatientTreatmentStatus $s) => $s === PatientTreatmentStatus::COMPLETED)) {
            return 'completed';
        }

        return 'default';
    }

    /**
     * @param  array<int, string|int|null>  $toothNumbers
     * @return list<string>
     */
    private function normalizeToothNumbers(array $toothNumbers): array
    {
        return collect($toothNumbers)
            ->filter(fn ($n) => $n !== null && $n !== '')
            ->map(fn ($n) => (string) $n)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $toothNumbers
     */
    private function syncTeeth(PatientTreatment $treatment, array $toothNumbers): void
    {
        $treatment->teeth()->delete();

        foreach ($toothNumbers as $number) {
            if (! preg_match('/^[1-4][1-8]$/', $number)) {
                throw ValidationException::withMessages([
                    'tooth_numbers' => "Invalid tooth number: {$number}.",
                ]);
            }

            $treatment->teeth()->create(['tooth_number' => $number]);
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
                'clinical_status' => 'Cannot cancel a treatment with a partially or fully paid invoice. Please issue a refund first.',
            ]);
        }
    }

    private function assertTreatmentCanBeDeleted(PatientTreatment $treatment): void
    {
        if ($treatment->sessions()->exists()) {
            throw ValidationException::withMessages([
                'treatment' => 'Cannot delete a treatment that already has sessions. Cancel it instead.',
            ]);
        }

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

    private function billableComponentQuantity(float $quantity, float $freeQuantity): string
    {
        $deduct = max(0, $quantity - $freeQuantity);

        return number_format($deduct, 2, '.', '');
    }

    private function clinical(PatientTreatment $treatment): PatientTreatmentStatus
    {
        return $treatment->clinical_status;
    }

    private function sessionStatus(TreatmentSession $session): TreatmentSessionStatus
    {
        return $session->status;
    }

    private function stepStatus(TreatmentSessionStep $step): SessionStepStatus
    {
        return $step->status;
    }

    private function freshTreatment(PatientTreatment $treatment): PatientTreatment
    {
        return $treatment->fresh($this->detailRelations());
    }

    /**
     * @return list<string>
     */
    private function listRelations(): array
    {
        return [
            'patient',
            'template.steps',
            'dentist',
            'plan',
            'teeth',
            'parentTreatment',
            'sessions.steps.templateStep',
            'sessions.dentist',
            'sessions.appointment',
            'invoiceItems',
        ];
    }

    /**
     * @return list<string>
     */
    private function detailRelations(): array
    {
        return [
            'patient',
            'template.steps.components.component',
            'dentist',
            'plan',
            'teeth',
            'parentTreatment',
            'sessions.steps.templateStep',
            'sessions.dentist',
            'sessions.appointment',
            'sessions.components',
            'invoiceItems',
        ];
    }

    /**
     * @param  Collection<int, InvoiceItem>  $items
     * @param  Collection<string, Invoice>  $invoices
     */
    private function allocatedPaidAmount($items, $invoices): float
    {
        $paid = 0.0;

        foreach ($items->groupBy('invoice_id') as $invoiceId => $invoiceItems) {
            /** @var Invoice|null $invoice */
            $invoice = $invoices->get($invoiceId);
            if (! $invoice) {
                continue;
            }

            $invoiceTotal = (float) $invoice->total_amount;
            $invoicePaid = (float) $invoice->payments->sum(function (Payment $payment) {
                return (float) $payment->amount + (float) ($payment->deduct_amount ?? 0);
            });
            $treatmentAmount = (float) $invoiceItems->sum(fn ($item) => (float) $item->price * (int) $item->quantity);

            if ($invoiceTotal <= 0) {
                continue;
            }

            $paid += $invoicePaid * ($treatmentAmount / $invoiceTotal);
        }

        return round($paid, 2);
    }
}
