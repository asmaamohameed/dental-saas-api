<?php

namespace App\Services;

use App\Billing\Actions\DeletePlannedItem;
use App\Billing\Actions\RecomputeInvoiceTotal;
use App\Billing\Domain\InvoiceItemState;
use App\Billing\Domain\InvoiceItemStateResolver;
use App\Billing\Queries\InvoiceItemFactsReader;
use App\Billing\Queries\InvoiceItemStateSql;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Enums\RefundType;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Billing\InvoiceFinancials;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    private const INVOICE_RELATIONS = [
        'patient',
        'appointment',
        'creator',
        'items.patientTreatment.template',
        'items.patientTreatment.teeth',
        'items.patientTreatment.sessions.dentist',
        'payments',
        'refunds.refundedBy',
    ];

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Invoice::query()
            ->with(['patient', 'creator'])
            ->withSum('payments as paid_amount', 'amount')
            ->withSum('payments as deducted_amount', 'deduct_amount');

        if (! empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (! empty($filters['patient_id'])) {
            $query->byPatient($filters['patient_id']);
        }

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $query->dateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        return $query->newestFirst()->paginate($perPage);
    }

    public function show(Invoice $invoice): Invoice
    {
        return $invoice->load(array_merge(self::INVOICE_RELATIONS, ['payments.receiver']));
    }

    public function createVisitFeeInvoiceAtCheckIn(Appointment $appointment, User $actor): void
    {
        if ($appointment->service_id === null || $appointment->service_id === '') {
            return;
        }

        if (bccomp(bcadd((string) ($appointment->fee ?? 0), '0', 2), '0', 2) <= 0) {
            return;
        }

        $appointment->loadMissing('service');
        if (! $appointment->service instanceof TreatmentTemplate) {
            return;
        }

        if (! $appointment->service->category->isVisit()) {
            return;
        }

        DB::transaction(function () use ($appointment, $actor) {
            if (
                InvoiceItem::query()
                    ->where('appointment_id', $appointment->id)
                    ->whereNull('voided_at')
                    ->lockForUpdate()
                    ->exists()
            ) {
                return;
            }

            $appointment->loadMissing('service');
            if (! $appointment->service instanceof TreatmentTemplate) {
                return;
            }

            $service = $appointment->service;
            $description = $service->name_en ?: $service->name_ar ?: 'Visit';
            $fee = bcadd((string) $appointment->fee, '0', 2);

            $invoice = Invoice::create([
                'patient_id' => $appointment->patient_id,
                'appointment_id' => $appointment->id,
                'treatment_plan_id' => null,
                'created_by' => $actor->id,
                'total_amount' => $fee,
                'status' => InvoiceStatus::UNPAID,
            ]);

            $invoice->items()->create([
                'appointment_id' => $appointment->id,
                'description' => $description,
                'price' => $fee,
                'quantity' => 1,
            ]);

            app(RecomputeInvoiceTotal::class)->handle($invoice);
        });
    }

    public function removeVisitFeeInvoiceWhenAppointmentCancelled(Appointment $appointment, User $actor): void
    {
        DB::transaction(function () use ($appointment, $actor) {
            /** @var InvoiceItem|null $item */
            $item = InvoiceItem::query()
                ->where('appointment_id', $appointment->id)
                ->whereNull('voided_at')
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                return;
            }

            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->lockForUpdate()->find($item->invoice_id);
            if ($invoice === null) {
                return;
            }

            if ($invoice->payments()->exists()) {
                return;
            }

            $state = app(InvoiceItemStateResolver::class)->resolve(
                app(InvoiceItemFactsReader::class)->read($item)
            );

            if ($state === InvoiceItemState::DONE) {
                return;
            }

            app(DeletePlannedItem::class)->handle($invoice, $item, $actor);

            $invoice->refresh();

            if ($invoice->items()->exists() || $invoice->payments()->exists()) {
                return;
            }

            $invoice->update(['status' => InvoiceStatus::CANCELLED->value]);
            $invoice->delete();
        });
    }

    public function createInitialInvoiceForTreatment(
        PatientTreatment $treatment,
        string $userId,
        ?string $contextAppointmentId = null,
    ): ?Invoice {
        return DB::transaction(function () use ($treatment, $userId, $contextAppointmentId) {
            $treatment->load(['template', 'patient']);

            $alreadyExists = InvoiceItem::query()
                ->where('patient_treatment_id', $treatment->id)
                ->exists();

            if ($alreadyExists) {
                return null;
            }

            $price = $treatment->total_price;
            $label = $this->treatmentInvoiceDescription($treatment);
            $planId = $treatment->treatment_plan_id;

            $invoice = null;
            if ($planId !== null) {
                $invoice = Invoice::query()
                    ->where('treatment_plan_id', $planId)
                    ->lockForUpdate()
                    ->first();
            }

            if ($invoice === null) {
                $invoice = Invoice::create([
                    'patient_id' => $treatment->patient_id,
                    'appointment_id' => null,
                    'treatment_plan_id' => $planId,
                    'created_by' => $userId,
                    'total_amount' => $price,
                    'status' => InvoiceStatus::UNPAID,
                ]);
            }

            $invoice->items()->create([
                'patient_treatment_id' => $treatment->id,
                'description' => $label,
                'price' => $price,
                'quantity' => 1,
            ]);

            app(RecomputeInvoiceTotal::class)->handle($invoice);

            if ($planId !== null) {
                $appointmentId = $this->resolvePlanSourceAppointmentId($treatment, $contextAppointmentId);
                if ($appointmentId !== null) {
                    $this->mergeVisitFeeLineIntoPlanInvoice($invoice, $appointmentId);
                }
            }

            $this->recomputeFinancialStatus($treatment);

            return $invoice->load(self::INVOICE_RELATIONS);
        });
    }

    public function syncTreatmentInvoiceItems(PatientTreatment $treatment): void
    {
        DB::transaction(function () use ($treatment) {
            $treatment->loadMissing(['template', 'teeth']);

            $items = InvoiceItem::query()
                ->where('patient_treatment_id', $treatment->id)
                ->with('invoice')
                ->get();

            if ($items->isEmpty()) {
                return;
            }

            $newPrice = $treatment->total_price;

            foreach ($items->groupBy('invoice_id') as $invoiceId => $invoiceItems) {
                /** @var Invoice|null $invoice */
                $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);
                if (! $invoice) {
                    continue;
                }

                if (
                    $invoice->status === InvoiceStatus::PAID
                    && bccomp((string) $invoice->total_amount, '0', 2) === 1
                ) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Cannot change treatment pricing while its invoice is fully paid.',
                    ]);
                }

                if (! in_array($invoice->status, [InvoiceStatus::UNPAID, InvoiceStatus::PARTIAL], true)) {
                    continue;
                }

                foreach ($invoiceItems as $item) {
                    $item->update([
                        'price' => $newPrice,
                        'quantity' => 1,
                    ]);
                }

                $billableTotal = app(InvoiceItemStateSql::class)->totalsForInvoice((string) $invoice->id)['total'];
                $totalPayments = InvoiceFinancials::appliedCredits($invoice);

                if (bccomp($billableTotal, (string) $totalPayments, 2) === -1) {
                    throw ValidationException::withMessages([
                        'invoice' => 'Updated treatment total would be less than payments already applied to this invoice.',
                    ]);
                }

                app(RecomputeInvoiceTotal::class)->handle($invoice);
            }

            $this->recomputeFinancialStatus($treatment);
        });
    }

    public function delete(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);
            InvoiceFinancials::assertMutable($lockedInvoice);
            $treatmentIds = $lockedInvoice->items()->pluck('patient_treatment_id')->filter()->unique();

            if ($lockedInvoice->payments()->exists()) {
                throw new InvoiceHasPaymentsException(
                    __('billing.invoice_has_payments')
                );
            }

            $lockedInvoice->update(['status' => InvoiceStatus::CANCELLED->value]);

            $deleted = (bool) $lockedInvoice->delete();

            foreach ($treatmentIds as $treatmentId) {
                $treatment = PatientTreatment::query()->find($treatmentId);
                if ($treatment) {
                    $this->recomputeFinancialStatus($treatment);
                }
            }

            return $deleted;
        });
    }

    public function removeUnpaidInvoicesForTreatment(PatientTreatment $treatment): void
    {
        $invoiceIds = InvoiceItem::query()
            ->where('patient_treatment_id', $treatment->id)
            ->pluck('invoice_id')
            ->unique();

        foreach ($invoiceIds as $invoiceId) {
            /** @var Invoice|null $invoice */
            $invoice = Invoice::query()->lockForUpdate()->find($invoiceId);

            if (! $invoice || $invoice->status !== InvoiceStatus::UNPAID) {
                continue;
            }

            if ($invoice->payments()->exists()) {
                continue;
            }

            $treatmentItemIds = $invoice->items()
                ->where('patient_treatment_id', $treatment->id)
                ->pluck('id');

            $invoice->items()->whereIn('id', $treatmentItemIds)->delete();

            $remainingItems = $invoice->items()->get();

            if ($remainingItems->isEmpty()) {
                $invoice->update(['status' => InvoiceStatus::CANCELLED]);
                $invoice->delete();

                continue;
            }

            app(RecomputeInvoiceTotal::class)->handle($invoice);
        }

        $this->recomputeFinancialStatus($treatment);
    }

    public function recalculateStatus(Invoice $invoice): void
    {
        $invoice->load(['payments', 'refunds']);

        if (InvoiceFinancials::isClosed($invoice)) {
            return;
        }

        if ($invoice->refunds->contains(fn ($refund) => $refund->type === RefundType::FULL)) {
            if ($invoice->status !== InvoiceStatus::REFUNDED) {
                $invoice->update(['status' => InvoiceStatus::REFUNDED]);
            }

            $this->recomputeFinancialStatusesForInvoice($invoice);

            return;
        }

        $status = InvoiceFinancials::deriveOpenStatus($invoice);

        if ($invoice->status !== $status) {
            $invoice->update(['status' => $status]);
        }

        $this->recomputeFinancialStatusesForInvoice($invoice);
    }

    public function recomputeFinancialStatusesForInvoice(Invoice $invoice): void
    {
        $treatmentIds = $invoice->items()->pluck('patient_treatment_id')->filter()->unique();

        foreach ($treatmentIds as $treatmentId) {
            $treatment = PatientTreatment::query()->find($treatmentId);
            if ($treatment) {
                $this->recomputeFinancialStatus($treatment);
            }
        }
    }

    public function recomputeFinancialStatus(PatientTreatment $treatment): void
    {
        $items = InvoiceItem::query()
            ->where('patient_treatment_id', $treatment->id)
            ->with('invoice.payments')
            ->get();

        if ($items->isEmpty()) {
            if ($treatment->financial_status !== FinancialStatus::UNPAID) {
                $treatment->update(['financial_status' => FinancialStatus::UNPAID]);
            }

            return;
        }

        $totalBilled = (float) $items->sum(fn ($item) => (float) $item->price * (int) $item->quantity);
        $paid = 0.0;

        foreach ($items->groupBy('invoice_id') as $invoiceItems) {
            $invoice = $invoiceItems->first()?->invoice;
            if (! $invoice) {
                continue;
            }

            $invoiceTotal = (float) $invoice->total_amount;
            $invoicePaid = InvoiceFinancials::appliedCredits($invoice);
            $treatmentAmount = (float) $invoiceItems->sum(fn ($item) => (float) $item->price * (int) $item->quantity);

            if ($invoiceTotal <= 0) {
                continue;
            }

            $paid += $invoicePaid * ($treatmentAmount / $invoiceTotal);
        }

        $status = match (true) {
            $paid + 0.009 >= $totalBilled && $totalBilled > 0 => FinancialStatus::PAID,
            $paid > 0 => FinancialStatus::PARTIAL,
            default => FinancialStatus::UNPAID,
        };

        if ($treatment->financial_status !== $status) {
            $treatment->update(['financial_status' => $status]);
        }
    }

    public function getPatientSummary(string $patientId): array
    {
        $invoices = Invoice::where('patient_id', $patientId)->with(['payments', 'refunds'])->get();
        $invoiceIds = $invoices->pluck('id');

        $billableInvoices = $invoices->reject(
            fn (Invoice $invoice) => in_array($invoice->status, [InvoiceStatus::REFUNDED, InvoiceStatus::CANCELLED], true)
        );
        $totalBilled = $billableInvoices->sum('total_amount');
        $totalPaid = (float) Payment::whereIn('invoice_id', $invoiceIds)->sum('amount');
        $totalRefunded = (float) Refund::whereIn('invoice_id', $invoiceIds)->sum('amount');
        $totalCollected = max(0, $totalPaid - $totalRefunded);
        $totalRemaining = $billableInvoices->sum(fn (Invoice $invoice) => InvoiceFinancials::remainingAmount($invoice));

        return [
            'patient_id' => $patientId,
            'total_invoices_count' => $invoices->count(),
            'total_billed' => (float) $totalBilled,
            'total_paid' => $totalPaid,
            'total_collected' => (float) $totalCollected,
            'total_refunded' => $totalRefunded,
            'total_remaining' => (float) max(0, $totalRemaining),
        ];
    }

    private function treatmentInvoiceDescription(PatientTreatment $treatment): string
    {
        $name = $treatment->template?->name_en ?: $treatment->template?->name_ar ?: 'Patient treatment';
        $teeth = $treatment->toothNumbers();

        if ($teeth === []) {
            return $name;
        }

        return $name.' ('.implode(', ', $teeth).')';
    }

    private function resolvePlanSourceAppointmentId(
        PatientTreatment $treatment,
        ?string $contextAppointmentId,
    ): ?string {
        if ($contextAppointmentId !== null && $contextAppointmentId !== '') {
            return $contextAppointmentId;
        }

        if ($treatment->treatment_plan_id === null) {
            return null;
        }

        $appointmentId = Appointment::query()
            ->where('patient_id', $treatment->patient_id)
            ->where('treatment_plan_id', $treatment->treatment_plan_id)
            ->value('id');

        return $appointmentId !== null ? (string) $appointmentId : null;
    }

    private function mergeVisitFeeLineIntoPlanInvoice(Invoice $planInvoice, string $appointmentId): void
    {
        /** @var InvoiceItem|null $visitItem */
        $visitItem = InvoiceItem::query()
            ->where('appointment_id', $appointmentId)
            ->whereNull('voided_at')
            ->lockForUpdate()
            ->first();

        if ($visitItem === null) {
            return;
        }

        if ((string) $visitItem->invoice_id === (string) $planInvoice->id) {
            return;
        }

        /** @var Invoice|null $visitInvoice */
        $visitInvoice = Invoice::query()->lockForUpdate()->find($visitItem->invoice_id);
        if ($visitInvoice === null) {
            return;
        }

        if ($visitInvoice->payments()->exists()) {
            return;
        }

        $visitItem->update(['invoice_id' => $planInvoice->id]);

        app(RecomputeInvoiceTotal::class)->handle($planInvoice->fresh());
        app(RecomputeInvoiceTotal::class)->handle($visitInvoice->fresh());

        $visitInvoice->refresh();

        if ($visitInvoice->items()->exists() || $visitInvoice->payments()->exists()) {
            return;
        }

        $visitInvoice->update(['status' => InvoiceStatus::CANCELLED->value]);
        $visitInvoice->delete();
    }
}
