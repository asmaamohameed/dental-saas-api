<?php

namespace App\Services;

use App\Billing\Actions\RecomputeInvoiceTotal;
use App\Billing\Domain\Exceptions\CannotMutateDoneItem;
use App\Billing\Domain\Exceptions\CannotMutateVoidedItem;
use App\Billing\Domain\Exceptions\InvoiceClosedException;
use App\Billing\Domain\InvoiceItemState;
use App\Billing\Domain\InvoiceItemStateResolver;
use App\Billing\Queries\InvoiceItemFactsReader;
use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly InvoiceItemFactsReader $factsReader,
        private readonly InvoiceItemStateResolver $stateResolver,
    ) {}

    private const INVOICE_RELATIONS = [
        'patient',
        'appointment',
        'creator',
        'items.patientTreatment.template',
        'items.patientTreatment.teeth',
        'items.patientTreatment.sessions.dentist',
        'payments',
        'payments.receiver',
        'refunds',
        'refunds.receiver',
        'refunds.invoiceItem',
        'items.refunds',
    ];

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Invoice::query()
            ->with(['patient', 'creator'])
            ->withSum('payments as paid_amount', 'amount')
            ->withSum('payments as deducted_amount', 'deduct_amount')
            ->withSum('refunds as refunded_amount', 'amount');

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
            unset($data['items'], $data['total_amount'], $data['status']);

            $tenantId = app(CurrentTenant::class)->id();
            $resolvedItems = [];

            foreach ($itemsData as $item) {
                [$price, $description] = $this->resolveItemPriceAndDescription($item, $tenantId);

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
                'total_amount' => '0.00',
                'status' => InvoiceStatus::UNPAID,
            ]);

            foreach ($resolvedItems as $resolvedItem) {
                $invoice->items()->create($resolvedItem);
            }

            $this->recomputeTotal($invoice);

            return $invoice->load(self::INVOICE_RELATIONS);
        });
    }

    public function show(Invoice $invoice): Invoice
    {
        return $invoice->load(self::INVOICE_RELATIONS);
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
                'total_amount' => '0.00',
                'status' => InvoiceStatus::UNPAID,
            ]);

            $invoice->items()->create([
                'patient_treatment_id' => $treatment->id,
                'description' => $label,
                'price' => $price,
                'quantity' => 1,
            ]);

            $this->recomputeTotal($invoice);

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

                if (in_array($invoice->status, [InvoiceStatus::CANCELLED, InvoiceStatus::REFUNDED], true)) {
                    continue;
                }

                foreach ($invoiceItems as $item) {
                    $item->update([
                        'price' => $newPrice,
                        'quantity' => 1,
                    ]);
                }

                $this->recomputeTotal($invoice);
            }

            $this->recomputeFinancialStatus($treatment);
        });
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            /** @var Invoice $lockedInvoice */
            $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);

            InvoiceClosedException::throwIf($lockedInvoice->status);
            unset($data['total_amount'], $data['status']);

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

            if (
                $lockedInvoice->treatment_plan_id !== null
                && isset($data['patient_id'])
                && (string) $data['patient_id'] !== (string) $lockedInvoice->patient_id
            ) {
                throw ValidationException::withMessages([
                    'patient_id' => 'The patient on a plan invoice cannot be changed.',
                ]);
            }

            $itemsChanged = false;

            if (isset($data['items'])) {
                $itemsData = $data['items'];
                unset($data['items']);
                $itemsChanged = true;

                if ($lockedInvoice->status === InvoiceStatus::PARTIAL) {
                    throw ValidationException::withMessages([
                        'items' => 'Partially paid invoices cannot be updated.',
                    ]);
                }

                $tenantId = app(CurrentTenant::class)->id();

                foreach ($itemsData as $item) {
                    [$price, $description] = $this->resolveItemPriceAndDescription($item, $tenantId);
                    $payload = [
                        'description' => $description,
                        'price' => $price,
                        'quantity' => $item['quantity'],
                    ];
                    if (array_key_exists('patient_treatment_id', $item)) {
                        $payload['patient_treatment_id'] = $item['patient_treatment_id'];
                    }

                    if (! empty($item['id'])) {
                        $existing = $lockedInvoice->items()->whereKey($item['id'])->first();
                        if (! $existing) {
                            throw ValidationException::withMessages([
                                'items' => 'One or more invoice lines do not belong to this invoice.',
                            ]);
                        }

                        $this->assertLineCanBeEdited($existing);
                        $existing->update($payload);

                        continue;
                    }

                    $lockedInvoice->items()->create($payload);
                }
            }

            if ($data !== []) {
                $lockedInvoice->update($data);
            }

            if ($itemsChanged) {
                $this->recomputeTotal($lockedInvoice);
            } else {
                $this->recalculateStatus($lockedInvoice);
            }

            return $lockedInvoice->load(self::INVOICE_RELATIONS);
        });
    }

    public function delete(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);
            $treatmentIds = $lockedInvoice->items()->pluck('patient_treatment_id')->filter()->unique();

            if ($lockedInvoice->payments()->exists() || $lockedInvoice->refunds()->exists()) {
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

            if ($invoice->payments()->exists() || $invoice->refunds()->exists()) {
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

            $this->recomputeTotal($invoice);
        }

        $this->recomputeFinancialStatus($treatment);
    }

    public function recalculateStatus(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::CANCELLED) {
            return;
        }

        $invoice->forgetBalance();

        $status = $invoice->invoiceBalance()->status();

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
            ->with(['invoice.payments', 'invoice.refunds'])
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
            $invoicePaid = $invoice->settledAmount();
            $treatmentAmount = (float) $invoiceItems->sum(fn ($item) => (float) $item->price * (int) $item->quantity);

            if ($invoiceTotal <= 0) {
                continue;
            }

            $paid += $invoicePaid * ($treatmentAmount / $invoiceTotal);
        }

        $status = match (true) {
            $totalBilled <= 0 => FinancialStatus::PAID,
            $paid + 0.009 >= $totalBilled => FinancialStatus::PAID,
            $paid > 0 => FinancialStatus::PARTIAL,
            default => FinancialStatus::UNPAID,
        };

        if ($treatment->financial_status !== $status) {
            $treatment->update(['financial_status' => $status]);
        }
    }

    public function getPatientSummary(string $patientId): array
    {
        $invoices = Invoice::query()
            ->where('patient_id', $patientId)
            ->withSum('payments as paid_amount', 'amount')
            ->withSum('payments as deducted_amount', 'deduct_amount')
            ->withSum('refunds as refunded_amount', 'amount')
            ->get();

        $totalBilled = $invoices->sum(fn (Invoice $invoice) => (float) $invoice->total_amount);
        $totalPaid = $invoices->sum(fn (Invoice $invoice) => $invoice->netPaid());
        $totalRemaining = $invoices->sum(fn (Invoice $invoice) => (float) $invoice->remaining_amount);

        return [
            'patient_id' => $patientId,
            'total_invoices_count' => $invoices->count(),
            'total_billed' => (float) $totalBilled,
            'total_paid' => (float) $totalPaid,
            'total_remaining' => (float) max(0, $totalRemaining),
        ];
    }

    private function recomputeTotal(Invoice $invoice): void
    {
        app(RecomputeInvoiceTotal::class)->handle($invoice);
    }

    private function assertLineCanBeEdited(InvoiceItem $item): void
    {
        $state = $this->stateResolver->resolve($this->factsReader->read($item));

        if ($state === InvoiceItemState::DONE) {
            throw CannotMutateDoneItem::locked();
        }

        if ($state === InvoiceItemState::VOIDED) {
            throw CannotMutateVoidedItem::locked();
        }
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
                isset($item['price']) ? (float) $item['price'] : $treatment->total_price,
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
