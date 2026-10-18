<?php

namespace App\Services;

use App\Billing\Audit\BillingAuditLogger;
use App\Enums\AppointmentStatus;
use App\Enums\AuditAction;
use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentCategory;
use App\Enums\TreatmentPriority;
use App\Enums\TreatmentScope;
use App\Enums\TreatmentSessionStatus;
use App\Enums\TreatmentToothStatus;
use App\Enums\TreatmentType;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentTooth;
use App\Models\TreatmentSession;
use App\Models\TreatmentTemplate;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class PatientTreatmentService
{
    public function __construct(
        private readonly InvoiceService $invoiceService,
        private readonly BillingAuditLogger $auditLogger,
    ) {}

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

    public function createFromTemplate(array $data, User $actor): PatientTreatment
    {
        return DB::transaction(function () use ($data, $actor) {
            /** @var TreatmentTemplate $template */
            $template = TreatmentTemplate::query()->findOrFail($data['treatment_template_id']);

            if ($template->category === TreatmentCategory::VISIT) {
                throw ValidationException::withMessages([
                    'treatment_template_id' => 'Visit services cannot be used to create a patient treatment.',
                ]);
            }

            $scope = $template->scope;
            $toothNumbers = $this->normalizeToothNumbers($data['tooth_numbers'] ?? []);
            $this->assertScopeToothRules($scope, $toothNumbers);

            $unitPrice = array_key_exists('agreed_price', $data) && $data['agreed_price'] !== null
                ? $data['agreed_price']
                : $template->default_price;

            $dentistId = $data['_dentist_id'] ?? $this->resolveDentistForNewTreatment(
                $actor,
                (string) $data['patient_id'],
                $data['appointment_id'] ?? null
            );

            $treatment = PatientTreatment::create([
                'patient_id' => $data['patient_id'],
                'treatment_plan_id' => $data['treatment_plan_id'] ?? null,
                'treatment_template_id' => $template->id,
                'scope' => $scope,
                'scope_detail' => $data['scope_detail'] ?? null,
                'agreed_price' => $unitPrice,
                'clinical_status' => PatientTreatmentStatus::PLANNED,
                'financial_status' => FinancialStatus::UNPAID,
                'consent_status' => $data['consent_status'] ?? ConsentStatus::NOT_REQUIRED,
                'consent_document_ref' => $data['consent_document_ref'] ?? null,
                'parent_treatment_id' => $data['parent_treatment_id'] ?? null,
                'treatment_type' => $data['treatment_type'] ?? TreatmentType::ORIGINAL,
                'dentist_id' => $dentistId,
                'diagnosis' => $data['diagnosis'] ?? null,
                'priority' => $data['priority'] ?? TreatmentPriority::ROUTINE->value,
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            if ($scope === TreatmentScope::TOOTH) {
                $this->createTeeth($treatment, $toothNumbers, (string) $unitPrice);
            }

            $treatment = $this->freshTreatment($treatment);
            $this->invoiceService->createInitialInvoiceForTreatment($treatment, $actor->id);
            $this->invoiceService->recomputeFinancialStatus($treatment);

            return $this->freshTreatment($treatment);
        });
    }

    public function update(PatientTreatment $treatment, array $data, User $user): PatientTreatment
    {
        return DB::transaction(function () use ($treatment, $data, $user) {
            $toothNumbers = array_key_exists('tooth_numbers', $data)
                ? $this->normalizeToothNumbers($data['tooth_numbers'])
                : null;
            unset(
                $data['tooth_numbers'],
                $data['tooth_number'],
                $data['status'],
                $data['doctor_id'],
                $data['actual_price'],
                $data['clinical_status'],
                $data['financial_status'],
                $data['cancellation_reason'],
            );

            if (array_key_exists('agreed_price', $data)) {
                $this->applyUnitPriceChange($treatment, (float) $data['agreed_price'], $user);
                unset($data['agreed_price']);
            }

            $allowed = collect($data)->only([
                'treatment_plan_id',
                'dentist_id',
                'diagnosis',
                'priority',
                'notes',
                'consent_status',
                'consent_document_ref',
                'scope_detail',
            ])->all();

            if ($allowed !== []) {
                $treatment->update($allowed);
            }

            if ($toothNumbers !== null) {
                if ($treatment->scope !== TreatmentScope::TOOTH) {
                    throw ValidationException::withMessages([
                        'tooth_numbers' => 'Tooth numbers cannot be changed for this treatment scope.',
                    ]);
                }
                $this->assertScopeToothRules($treatment->scope, $toothNumbers);
                $this->syncTeeth($treatment, $toothNumbers, (string) $treatment->agreed_price);
                $this->invoiceService->syncTreatmentInvoiceItems($treatment);
            }

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

    /**
     * @param  array{teeth: array<int, array{tooth_number: string, status?: string, price?: float|int|string}>}  $data
     */
    public function updateTeeth(PatientTreatment $treatment, array $data, User $user): PatientTreatment
    {
        return DB::transaction(function () use ($treatment, $data, $user) {
            if ($treatment->scope !== TreatmentScope::TOOTH) {
                throw ValidationException::withMessages([
                    'teeth' => 'Per-tooth updates apply only to tooth-scoped treatments.',
                ]);
            }

            $treatment->load('teeth');
            $byNumber = $treatment->teeth->keyBy(fn (PatientTreatmentTooth $t) => (string) $t->tooth_number);

            foreach ($data['teeth'] as $index => $row) {
                $number = (string) $row['tooth_number'];
                /** @var PatientTreatmentTooth|null $tooth */
                $tooth = $byNumber->get($number);
                if ($tooth === null) {
                    throw ValidationException::withMessages([
                        "teeth.{$index}.tooth_number" => "Tooth {$number} is not part of this treatment.",
                    ]);
                }

                $previousStatus = $tooth->status;

                if (isset($row['status'])) {
                    $newStatus = TreatmentToothStatus::from((string) $row['status']);
                    if ($previousStatus === TreatmentToothStatus::DONE && $newStatus !== TreatmentToothStatus::DONE) {
                        if (! $this->userCanRevertDoneTooth($user)) {
                            throw new AccessDeniedHttpException('Only owners and doctors may reopen a completed tooth.');
                        }
                    }
                    $tooth->status = $newStatus;
                    if ($newStatus === TreatmentToothStatus::DONE) {
                        $tooth->completed_at = now();
                    } elseif ($previousStatus === TreatmentToothStatus::DONE) {
                        $tooth->completed_at = null;
                    }
                }

                if (isset($row['price'])) {
                    if (! Gate::forUser($user)->allows('canChangePrice')) {
                        throw new AccessDeniedHttpException('You are not allowed to change treatment prices.');
                    }
                    if ($tooth->status === TreatmentToothStatus::DONE) {
                        throw ValidationException::withMessages([
                            "teeth.{$index}.price" => 'Price cannot be changed for a completed tooth.',
                        ]);
                    }

                    $oldPrice = (string) $tooth->price;
                    $newPrice = number_format((float) $row['price'], 2, '.', '');
                    if ($oldPrice !== $newPrice) {
                        $tooth->price = $newPrice;
                        $this->logToothPriceChange($treatment, $number, $oldPrice, $newPrice, $user);
                    }
                }

                $tooth->save();
            }

            $this->recomputeClinicalStatus($treatment, $user->id);
            $treatment->refresh();
            $this->invoiceService->syncTreatmentInvoiceItems($treatment);

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

            $unitPrice = $data['agreed_price']
                ?? ($treatment->template !== null ? $treatment->template->default_price : null)
                ?? $treatment->agreed_price;

            $payload = [
                'patient_id' => $treatment->patient_id,
                'treatment_plan_id' => $data['treatment_plan_id'] ?? $treatment->treatment_plan_id,
                'treatment_template_id' => $data['treatment_template_id'] ?? $treatment->treatment_template_id,
                'agreed_price' => $unitPrice,
                '_dentist_id' => $data['dentist_id'] ?? $treatment->dentist_id,
                'diagnosis' => $data['diagnosis'] ?? $treatment->diagnosis,
                'priority' => $data['priority'] ?? $treatment->priority->value,
                'notes' => $data['notes'] ?? null,
                'consent_status' => $data['consent_status'] ?? $treatment->consent_status->value,
                'scope_detail' => $data['scope_detail'] ?? $treatment->scope_detail,
                'parent_treatment_id' => $treatment->id,
                'treatment_type' => TreatmentType::RETREATMENT->value,
                'tooth_numbers' => $data['tooth_numbers'] ?? $treatment->teeth()->pluck('tooth_number')->all(),
            ];

            /** @var User $actor */
            $actor = User::query()->findOrFail($userId);

            return $this->createFromTemplate($payload, $actor);
        });
    }

    private function resolveDentistForNewTreatment(User $actor, string $patientId, ?string $appointmentId): string
    {
        if ($actor->hasAnyClinicRole([UserRole::DOCTOR, UserRole::OWNER])) {
            return $actor->id;
        }

        if ($appointmentId === null || $appointmentId === '') {
            throw ValidationException::withMessages([
                'appointment_id' => 'A treating dentist is required. Provide appointment_id for this visit or sign in as a doctor.',
            ]);
        }

        /** @var Appointment|null $appointment */
        $appointment = Appointment::query()->find($appointmentId);
        if ($appointment === null) {
            throw ValidationException::withMessages([
                'appointment_id' => 'The selected appointment is invalid.',
            ]);
        }

        if ((string) $appointment->patient_id !== $patientId) {
            throw ValidationException::withMessages([
                'appointment_id' => 'The appointment does not belong to this patient.',
            ]);
        }

        if (in_array($appointment->status, [AppointmentStatus::CANCELLED, AppointmentStatus::NO_SHOW], true)) {
            throw ValidationException::withMessages([
                'appointment_id' => 'Cannot use a cancelled or no-show appointment to assign a treating dentist.',
            ]);
        }

        if ($appointment->doctor_id === null) {
            throw ValidationException::withMessages([
                'appointment_id' => 'The selected appointment has no assigned doctor.',
            ]);
        }

        $doctorId = (string) $appointment->doctor_id;
        $this->assertActiveTreatingClinician($doctorId, (string) $actor->tenant_id, 'appointment_id', doctorsOnly: true);

        return $doctorId;
    }

    private function resolveDentistForNewSession(PatientTreatment $treatment, string $userId): ?string
    {
        /** @var User|null $actor */
        $actor = User::query()->find($userId);
        if (
            $actor !== null
            && $actor->is_active
            && $actor->hasAnyClinicRole([UserRole::DOCTOR])
        ) {
            return $actor->id;
        }

        return $treatment->dentist_id;
    }

    private function assertActiveTreatingClinician(
        string $userId,
        string $tenantId,
        string $errorKey = 'dentist_id',
        bool $doctorsOnly = false,
    ): void {
        /** @var User|null $user */
        $user = User::query()
            ->where('id', $userId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        $allowedRoles = $doctorsOnly
            ? [UserRole::DOCTOR]
            : [UserRole::DOCTOR, UserRole::OWNER];

        if ($user === null || ! $user->hasAnyClinicRole($allowedRoles)) {
            throw ValidationException::withMessages([
                $errorKey => 'The selected user is not an active doctor in this clinic.',
            ]);
        }
    }

    /**
     * @return array{session: TreatmentSession}
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

            $toothNumbers = $this->validateSessionToothNumbers($treatment, $data['tooth_numbers'] ?? null);

            $explicitDentist = array_key_exists('dentist_id', $data)
                && $data['dentist_id'] !== null
                && $data['dentist_id'] !== '';
            $dentistId = $explicitDentist
                ? (string) $data['dentist_id']
                : $this->resolveDentistForNewSession($treatment, $userId);
            if ($dentistId !== null && $dentistId !== '') {
                $this->assertActiveTreatingClinician(
                    (string) $dentistId,
                    (string) $treatment->tenant_id,
                    doctorsOnly: $explicitDentist,
                );
            }

            $session = $treatment->sessions()->create([
                'appointment_id' => $data['appointment_id'] ?? null,
                'dentist_id' => $dentistId,
                'session_date' => $data['session_date'] ?? now(),
                'status' => $data['status'] ?? TreatmentSessionStatus::SCHEDULED,
                'notes' => $data['notes'] ?? null,
                'tooth_numbers' => $toothNumbers,
            ]);

            $this->afterSessionMutation($session->fresh(['treatment.teeth']), $userId);

            return [
                'session' => $session->fresh(['dentist', 'appointment']),
            ];
        });
    }

    /**
     * @return array{session: TreatmentSession}
     */
    public function updateSession(TreatmentSession $session, array $data, string $userId): array
    {
        return DB::transaction(function () use ($session, $data, $userId) {
            $session->load(['treatment.teeth']);
            $previous = $this->sessionStatus($session);

            if (array_key_exists('tooth_numbers', $data)) {
                $data['tooth_numbers'] = $this->validateSessionToothNumbers($session->treatment, $data['tooth_numbers']);
            }

            if (array_key_exists('dentist_id', $data) && $data['dentist_id'] !== null && $data['dentist_id'] !== '') {
                $this->assertActiveTreatingClinician((string) $data['dentist_id'], (string) $session->tenant_id, doctorsOnly: true);
            }

            $session->update(collect($data)->only([
                'appointment_id',
                'dentist_id',
                'session_date',
                'status',
                'notes',
                'tooth_numbers',
            ])->all());

            $session->refresh();
            $current = $this->sessionStatus($session);
            if ($previous !== TreatmentSessionStatus::COMPLETED && $current === TreatmentSessionStatus::COMPLETED) {
                $this->promotePlannedTeethOnSessionComplete($session);
            }

            $this->recomputeClinicalStatus($session->treatment, $userId);

            return [
                'session' => $session->fresh(['dentist', 'appointment']),
            ];
        });
    }

    public function syncSessionDentistsFromAppointmentDoctor(string $appointmentId, string $dentistId): void
    {
        TreatmentSession::query()
            ->where('appointment_id', $appointmentId)
            ->where('status', TreatmentSessionStatus::SCHEDULED)
            ->update(['dentist_id' => $dentistId]);
    }

    public function syncSessionsFromAppointment(string $appointmentId, TreatmentSessionStatus $status, string $userId): void
    {
        $sessions = TreatmentSession::query()
            ->where('appointment_id', $appointmentId)
            ->where('status', TreatmentSessionStatus::SCHEDULED)
            ->with(['treatment.teeth'])
            ->get();

        foreach ($sessions as $session) {
            $previous = $this->sessionStatus($session);
            $session->update(['status' => $status]);
            $session->refresh();
            if ($previous !== TreatmentSessionStatus::COMPLETED && $status === TreatmentSessionStatus::COMPLETED) {
                $this->promotePlannedTeethOnSessionComplete($session);
            }
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
            ->with(['invoice.payments', 'invoice.refunds'])
            ->get();

        $invoiceIds = $items->pluck('invoice_id')->unique();
        $invoices = Invoice::query()->whereIn('id', $invoiceIds)->with(['payments', 'refunds'])->get()->keyBy('id');

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
        $treatment->load(['teeth', 'sessions']);
        $clinical = $this->clinical($treatment);

        if (in_array($clinical, [PatientTreatmentStatus::FAILED, PatientTreatmentStatus::ON_HOLD], true)) {
            return;
        }

        if ($clinical === PatientTreatmentStatus::CANCELLED && $treatment->scope !== TreatmentScope::TOOTH) {
            return;
        }

        if ($treatment->scope === TreatmentScope::TOOTH) {
            $this->recomputeToothScopedClinicalStatus($treatment);

            return;
        }

        $hasCompletedSession = $treatment->sessions->contains(
            fn (TreatmentSession $session) => $this->sessionStatus($session) === TreatmentSessionStatus::COMPLETED
        );

        if ($clinical === PatientTreatmentStatus::PLANNED && $hasCompletedSession) {
            $treatment->clinical_status = PatientTreatmentStatus::IN_PROGRESS;
            $treatment->started_at ??= now();
            $treatment->completed_at = null;
            $treatment->save();
        }

        unset($userId);
    }

    public function userCanRevertDoneTooth(User $user): bool
    {
        return $user->hasAnyClinicRole([UserRole::OWNER, UserRole::DOCTOR]);
    }

    private function recomputeToothScopedClinicalStatus(PatientTreatment $treatment): void
    {
        $clinical = $this->clinical($treatment);
        $teeth = $treatment->teeth;

        if ($clinical === PatientTreatmentStatus::CANCELLED) {
            if (! $treatment->auto_cancelled) {
                return;
            }

            $hasNonSkipped = $teeth->contains(
                fn (PatientTreatmentTooth $t) => $t->status !== TreatmentToothStatus::SKIPPED
            );
            if (! $hasNonSkipped) {
                return;
            }

            $treatment->cancellation_reason = null;
            $treatment->cancelled_at = null;
            $treatment->auto_cancelled = false;
            if ($teeth->contains(
                fn (PatientTreatmentTooth $t) => in_array($t->status, [TreatmentToothStatus::IN_PROGRESS, TreatmentToothStatus::DONE], true)
            )) {
                $treatment->clinical_status = PatientTreatmentStatus::IN_PROGRESS;
                $treatment->started_at ??= now();
                $treatment->completed_at = null;
            } else {
                $treatment->clinical_status = PatientTreatmentStatus::PLANNED;
                $treatment->completed_at = null;
            }
            $treatment->save();
            $clinical = $this->clinical($treatment);
        }

        $anyInProgressOrDone = $teeth->contains(
            fn (PatientTreatmentTooth $t) => in_array($t->status, [TreatmentToothStatus::IN_PROGRESS, TreatmentToothStatus::DONE], true)
        );

        if ($clinical === PatientTreatmentStatus::PLANNED && $anyInProgressOrDone) {
            $treatment->clinical_status = PatientTreatmentStatus::IN_PROGRESS;
            $treatment->started_at ??= now();
            $treatment->completed_at = null;
            $treatment->save();
            $clinical = PatientTreatmentStatus::IN_PROGRESS;
        }

        $allResolved = $teeth->isNotEmpty() && $teeth->every(
            fn (PatientTreatmentTooth $t) => in_array($t->status, [TreatmentToothStatus::DONE, TreatmentToothStatus::SKIPPED], true)
        );
        $atLeastOneDone = $teeth->contains(fn (PatientTreatmentTooth $t) => $t->status === TreatmentToothStatus::DONE);

        if ($clinical === PatientTreatmentStatus::IN_PROGRESS && $allResolved && $atLeastOneDone) {
            $treatment->clinical_status = PatientTreatmentStatus::COMPLETED;
            $treatment->completed_at = now();
            $treatment->save();

            return;
        }

        if ($allResolved && ! $atLeastOneDone) {
            if ($clinical === PatientTreatmentStatus::IN_PROGRESS && $clinical->canTransitionTo(PatientTreatmentStatus::CANCELLED)) {
                $treatment->clinical_status = PatientTreatmentStatus::CANCELLED;
                $treatment->auto_cancelled = true;
                $treatment->cancellation_reason ??= 'All teeth skipped';
                $treatment->cancelled_at ??= now();
                $treatment->completed_at = null;
                $treatment->save();
            }

            return;
        }

        if ($clinical === PatientTreatmentStatus::COMPLETED) {
            $reopened = $teeth->contains(
                fn (PatientTreatmentTooth $t) => in_array($t->status, [TreatmentToothStatus::PLANNED, TreatmentToothStatus::IN_PROGRESS], true)
            );
            if ($reopened) {
                $treatment->clinical_status = PatientTreatmentStatus::IN_PROGRESS;
                $treatment->completed_at = null;
                $treatment->save();
            }
        }
    }

    private function applyUnitPriceChange(PatientTreatment $treatment, float $newUnitPrice, User $user): void
    {
        if (! Gate::forUser($user)->allows('canChangePrice')) {
            throw new AccessDeniedHttpException('You are not allowed to change treatment prices.');
        }

        if ($treatment->scope === TreatmentScope::TOOTH) {
            $treatment->load('teeth');
            $updatedAny = false;

            foreach ($treatment->teeth as $tooth) {
                if (! in_array($tooth->status, [TreatmentToothStatus::PLANNED, TreatmentToothStatus::IN_PROGRESS], true)) {
                    continue;
                }

                $oldPrice = (string) $tooth->price;
                $formatted = number_format($newUnitPrice, 2, '.', '');
                if ($oldPrice !== $formatted) {
                    $tooth->update(['price' => $formatted]);
                    $this->logToothPriceChange($treatment, (string) $tooth->tooth_number, $oldPrice, $formatted, $user);
                    $updatedAny = true;
                }
            }

            if (! $updatedAny) {
                throw ValidationException::withMessages([
                    'agreed_price' => 'No teeth are available to reprice at the new unit price.',
                ]);
            }

            $treatment->update(['agreed_price' => number_format($newUnitPrice, 2, '.', '')]);
            $this->invoiceService->syncTreatmentInvoiceItems($treatment->fresh(['teeth', 'template']));

            return;
        }

        if ($treatment->clinical_status === PatientTreatmentStatus::COMPLETED) {
            throw ValidationException::withMessages([
                'agreed_price' => 'Agreed price cannot be changed after the treatment is completed.',
            ]);
        }

        $treatment->update(['agreed_price' => number_format($newUnitPrice, 2, '.', '')]);
        $this->invoiceService->syncTreatmentInvoiceItems($treatment->fresh(['teeth', 'template']));
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

        if ($target === PatientTreatmentStatus::COMPLETED && $treatment->scope === TreatmentScope::TOOTH) {
            $treatment->load('teeth');
            $unresolved = $treatment->teeth->contains(
                fn (PatientTreatmentTooth $t) => ! in_array($t->status, [TreatmentToothStatus::DONE, TreatmentToothStatus::SKIPPED], true)
            );
            if ($unresolved) {
                throw ValidationException::withMessages([
                    'clinical_status' => 'Mark the remaining teeth first.',
                ]);
            }
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

        $treatment->auto_cancelled = false;
        $treatment->clinical_status = $target;
        $treatment->save();
    }

    private function afterSessionMutation(TreatmentSession $session, string $userId): void
    {
        if ($this->sessionStatus($session) === TreatmentSessionStatus::COMPLETED) {
            $this->promotePlannedTeethOnSessionComplete($session);
        }
        $this->recomputeClinicalStatus($session->treatment, $userId);
    }

    private function promotePlannedTeethOnSessionComplete(TreatmentSession $session): void
    {
        if ($session->treatment->scope !== TreatmentScope::TOOTH) {
            return;
        }

        $session->loadMissing('treatment.teeth');
        $targetNumbers = $session->tooth_numbers;
        if ($targetNumbers === null || $targetNumbers === []) {
            return;
        }

        $teeth = $session->treatment->teeth;

        foreach ($teeth as $tooth) {
            if ($tooth->status !== TreatmentToothStatus::PLANNED) {
                continue;
            }
            if (! in_array((string) $tooth->tooth_number, $targetNumbers, true)) {
                continue;
            }
            $tooth->update(['status' => TreatmentToothStatus::IN_PROGRESS]);
        }
    }

    /**
     * @param  list<string>  $statuses
     */
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
    private function assertScopeToothRules(TreatmentScope $scope, array $toothNumbers): void
    {
        if ($scope === TreatmentScope::TOOTH) {
            if ($toothNumbers === []) {
                throw ValidationException::withMessages([
                    'tooth_numbers' => 'At least one tooth is required for tooth-scoped treatments.',
                ]);
            }

            foreach ($toothNumbers as $number) {
                if (! preg_match('/^[1-4][1-8]$/', $number)) {
                    throw ValidationException::withMessages([
                        'tooth_numbers' => "Invalid tooth number: {$number}.",
                    ]);
                }
            }

            return;
        }

        if ($toothNumbers !== []) {
            throw ValidationException::withMessages([
                'tooth_numbers' => 'Tooth numbers must not be sent for this treatment scope.',
            ]);
        }
    }

    /**
     * @param  list<string>  $toothNumbers
     */
    private function createTeeth(PatientTreatment $treatment, array $toothNumbers, string $unitPrice): void
    {
        foreach ($toothNumbers as $number) {
            $treatment->teeth()->create([
                'tooth_number' => $number,
                'status' => TreatmentToothStatus::PLANNED,
                'price' => $unitPrice,
            ]);
        }
    }

    /**
     * @param  list<string>  $toothNumbers
     */
    private function syncTeeth(PatientTreatment $treatment, array $toothNumbers, string $unitPrice): void
    {
        $treatment->teeth()->delete();
        $this->createTeeth($treatment, $toothNumbers, $unitPrice);
    }

    /**
     * @param  list<string>|null  $toothNumbers
     * @return list<string>|null
     */
    private function validateSessionToothNumbers(PatientTreatment $treatment, ?array $toothNumbers): ?array
    {
        if ($toothNumbers === null) {
            return null;
        }

        $normalized = $this->normalizeToothNumbers($toothNumbers);
        if ($treatment->scope !== TreatmentScope::TOOTH) {
            if ($normalized !== []) {
                throw ValidationException::withMessages([
                    'tooth_numbers' => 'Session tooth numbers apply only to tooth-scoped treatments.',
                ]);
            }

            return null;
        }

        $allowed = collect($treatment->teeth()->pluck('tooth_number')->all())->map(fn ($n) => (string) $n);
        foreach ($normalized as $number) {
            if (! preg_match('/^[1-4][1-8]$/', $number)) {
                throw ValidationException::withMessages([
                    'tooth_numbers' => "Invalid tooth number: {$number}.",
                ]);
            }
            if (! $allowed->contains($number)) {
                throw ValidationException::withMessages([
                    'tooth_numbers' => "Tooth {$number} is not part of this treatment.",
                ]);
            }
        }

        return $normalized;
    }

    private function logToothPriceChange(PatientTreatment $treatment, string $toothNumber, string $oldPrice, string $newPrice, User $user): void
    {
        $this->auditLogger->record(
            $user,
            $treatment,
            AuditAction::TREATMENT_TOOTH_PRICE_CHANGED,
            [
                'tooth_number' => $toothNumber,
                'price' => $oldPrice,
            ],
            [
                'tooth_number' => $toothNumber,
                'price' => $newPrice,
            ],
        );
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
        $treatment->load(['teeth', 'sessions']);

        if ($treatment->teeth->contains(fn (PatientTreatmentTooth $tooth) => $tooth->status === TreatmentToothStatus::DONE)) {
            throw ValidationException::withMessages([
                'treatment' => 'Cannot delete a treatment with a completed tooth.',
            ]);
        }

        if ($treatment->sessions->contains(
            fn (TreatmentSession $session) => $this->sessionStatus($session) === TreatmentSessionStatus::COMPLETED
        )) {
            throw ValidationException::withMessages([
                'treatment' => 'Cannot delete a treatment with a completed session.',
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

    private function clinical(PatientTreatment $treatment): PatientTreatmentStatus
    {
        return $treatment->clinical_status;
    }

    private function sessionStatus(TreatmentSession $session): TreatmentSessionStatus
    {
        return $session->status;
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
            'template',
            'dentist',
            'plan',
            'teeth',
            'parentTreatment',
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
            'template',
            'dentist',
            'plan',
            'teeth',
            'parentTreatment',
            'sessions.dentist',
            'sessions.appointment',
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
            $invoicePaid = $invoice->settledAmount();
            $treatmentAmount = (float) $invoiceItems->sum(fn ($item) => (float) $item->price * (int) $item->quantity);

            if ($invoiceTotal <= 0) {
                continue;
            }

            $paid += $invoicePaid * ($treatmentAmount / $invoiceTotal);
        }

        return round($paid, 2);
    }
}
