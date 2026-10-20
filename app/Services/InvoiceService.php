<?php

namespace App\Services;

use App\Billing\Actions\RecomputeInvoiceTotal;
use App\Billing\Domain\InvoiceItemState;
use App\Billing\Domain\InvoiceItemStateResolver;
use App\Billing\Queries\InvoiceItemFactsReader;
use App\Billing\Queries\InvoiceItemStateSql;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\Payment;
use App\Models\Refund;
use App\Support\Billing\InvoiceFinancials;
use App\Support\Tenancy\CurrentTenant;
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

    public function create(array $data, string $userId): Invoice
    {
        return DB::transaction(function () use ($data, $userId) {
            $itemsData = $data['items'];
            unset($data['items']);

            $tenantId = app(CurrentTenant::class)->id();
            $resolvedItems = [];
            $totalAmount = 0;

            foreach ($itemsData as $item) {
                [$price, $description] = $this->resolveItemPriceAndDescription($item, $tenantId);
                $totalAmount += $price * $item['quantity'];

                $resolvedItems[] = [
                    'patient_treatment_id' => $item['patient_treatment_id'] ?? null,
                    'description' => $description,
                    'price' => $price,
                    'quantity' => $item['quantity'],
                ];
            }

            $invoice = Invoice::create([
                ...$data,
                'created_by' => $userId,
                'total_amount' => $totalAmount,
                'status' => InvoiceStatus::UNPAID,
            ]);

            foreach ($resolvedItems as $resolvedItem) {
                $invoice->items()->create($resolvedItem);
            }

            app(RecomputeInvoiceTotal::class)->handle($invoice);

            $this->recomputeFinancialStatusesForInvoice($invoice);

            return $invoice->load(array_merge(self::INVOICE_RELATIONS, ['payments.receiver']));
        });
    }

    public function show(Invoice $invoice): Invoice
    {
        return $invoice->load(array_merge(self::INVOICE_RELATIONS, ['payments.receiver']));
    }

    public function createInitialInvoiceForTreatment(PatientTreatment $treatment, string $userId): ?Invoice
    {
        return DB::transaction(function () use ($treatment, $userId) {
            $treatment->load(['template', 'patient']);

            $alreadyExists = InvoiceItem::query()
                ->where('patient_treatment_id', $treatment->id)
                ->exists();

            if ($alreadyExists) {
                return null;
            }

            $price = $treatment->total_price;
            $label = $this->treatmentInvoiceDescription($treatment);

            $invoice = Invoice::create([
                'patient_id' => $treatment->patient_id,
                'appointment_id' => null,
                'created_by' => $userId,
                'total_amount' => $price,
                'status' => InvoiceStatus::UNPAID,
            ]);

            $invoice->items()->create([
                'patient_treatment_id' => $treatment->id,
                'description' => $label,
                'price' => $price,
                'quantity' => 1,
            ]);

            app(RecomputeInvoiceTotal::class)->handle($invoice);

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

    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            InvoiceFinancials::assertMutable($lockedInvoice);

            $scalarData = $data;
            unset($scalarData['items']);

            if ($lockedInvoice->status === InvoiceStatus::PAID && $scalarData !== []) {
                throw ValidationException::withMessages([
                    'invoice' => 'Fully paid invoices cannot be updated.',
                ]);
            }

            if (array_key_exists('appointment_id', $data)) {
                throw ValidationException::withMessages([
                    'appointment_id' => 'The appointment linked to an invoice cannot be changed after creation.',
                ]);
            }

            if (
                isset($data['patient_id'])
                && $lockedInvoice->status === InvoiceStatus::PARTIAL
                && (string) $data['patient_id'] !== (string) $lockedInvoice->patient_id
            ) {
                throw ValidationException::withMessages([
                    'patient_id' => 'The patient on an invoice with recorded payments cannot be changed.',
                ]);
            }

            $itemsChanged = false;

            if (isset($data['items'])) {
                $itemsChanged = true;
                $itemsData = $data['items'];
                unset($data['items']);

                if ($lockedInvoice->status === InvoiceStatus::PARTIAL) {
                    $onlyTreatmentItems = collect($itemsData)->every(
                        fn (array $item) => ! empty($item['patient_treatment_id'])
                    );

                    if (! $onlyTreatmentItems) {
                        throw ValidationException::withMessages([
                            'items' => 'Partially paid invoices cannot be updated.',
                        ]);
                    }
                }

                $tenantId = app(CurrentTenant::class)->id();
                $stateResolver = app(InvoiceItemStateResolver::class);
                $factsReader = app(InvoiceItemFactsReader::class);

                foreach ($itemsData as $index => $item) {
                    /** @var InvoiceItem|null $existing */
                    $existing = null;

                    if (! empty($item['id'])) {
                        $existing = $lockedInvoice->items()->find($item['id']);

                        if (! $existing || (string) $existing->invoice_id !== (string) $lockedInvoice->id) {
                            throw ValidationException::withMessages([
                                "items.{$index}.id" => 'One or more invoice lines are invalid for this invoice.',
                            ]);
                        }

                        $state = $stateResolver->resolve($factsReader->read($existing));

                        if (in_array($state, [InvoiceItemState::DONE, InvoiceItemState::VOIDED], true)) {
                            throw ValidationException::withMessages([
                                "items.{$index}.id" => __('billing.invoice_item_not_mutable'),
                            ]);
                        }

                        if (! array_key_exists('patient_treatment_id', $item)) {
                            $item['patient_treatment_id'] = $existing->patient_treatment_id;
                        }
                    }

                    [$price, $description] = $this->resolveItemPriceAndDescription($item, $tenantId);

                    $attributes = [
                        'patient_treatment_id' => $item['patient_treatment_id'] ?? null,
                        'description' => $description,
                        'price' => $price,
                        'quantity' => $item['quantity'],
                    ];

                    if ($existing) {
                        $existing->update($attributes);
                    } else {
                        $lockedInvoice->items()->create($attributes);
                    }
                }

                $this->assertBillableTotalNotBelowSettled($lockedInvoice, 'items');

                $lockedInvoice = app(RecomputeInvoiceTotal::class)->handle($lockedInvoice);
            }

            $lockedInvoice->update($data);

            if (! $itemsChanged) {
                $this->recalculateStatus($lockedInvoice);
            }

            return $lockedInvoice->load(self::INVOICE_RELATIONS);
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
                    'Cannot cancel an invoice that has recorded payments.'
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

        $totalBilled = $invoices->sum('total_amount');
        $totalPaid = Payment::whereIn('invoice_id', $invoiceIds)->sum('amount');
        $totalRefunded = Refund::whereIn('invoice_id', $invoiceIds)->sum('amount');
        $totalRemaining = $invoices->sum(fn (Invoice $invoice) => InvoiceFinancials::remainingAmount($invoice));

        return [
            'patient_id' => $patientId,
            'total_invoices_count' => $invoices->count(),
            'total_billed' => (float) $totalBilled,
            'total_paid' => (float) $totalPaid,
            'total_refunded' => (float) $totalRefunded,
            'total_remaining' => (float) max(0, $totalRemaining),
        ];
    }

    /**
     * @return array{0: float, 1: string|null}
     */
    private function resolveItemPriceAndDescription(array $item, string $tenantId): array
    {
        if (! empty($item['patient_treatment_id'])) {
            $treatment = PatientTreatment::with('template')
                ->where('tenant_id', $tenantId)
                ->find($item['patient_treatment_id']);

            if (! $treatment) {
                throw ValidationException::withMessages([
                    'items' => 'One or more selected treatments are invalid.',
                ]);
            }

            $label = $treatment->template?->name_en ?: $treatment->template?->name_ar ?: 'Patient treatment';

            return [
                (float) $treatment->total_price,
                $item['description'] ?? $this->treatmentInvoiceDescription($treatment),
            ];
        }

        if (! isset($item['price'])) {
            throw ValidationException::withMessages([
                'items' => 'Price is required when no treatment is selected.',
            ]);
        }

        return [
            (float) $item['price'],
            $item['description'] ?? null,
        ];
    }

    private function assertBillableTotalNotBelowSettled(Invoice $invoice, string $errorKey): void
    {
        $invoice->load(['payments', 'refunds']);
        $billableTotal = app(InvoiceItemStateSql::class)->totalsForInvoice((string) $invoice->id)['total'];
        $settled = (string) InvoiceFinancials::settledAmount($invoice);

        if (bccomp($billableTotal, $settled, 2) === -1) {
            throw ValidationException::withMessages([
                $errorKey => __('billing.total_below_settled'),
            ]);
        }
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
}
