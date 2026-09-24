<?php

namespace App\Services;

use App\Enums\FinancialStatus;
use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\Payment;
use App\Models\Service;
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
        'items.service',
        'items.patientTreatment.template',
        'items.patientTreatment.teeth',
        'items.patientTreatment.sessions.steps.templateStep',
        'items.patientTreatment.sessions.dentist',
        'payments',
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

        return $query->latest()->paginate($perPage);
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
                    'service_id' => $item['service_id'] ?? null,
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

            $price = (float) $treatment->agreed_price;
            $label = $treatment->template?->name_en ?: $treatment->template?->name_ar ?: 'Patient treatment';

            $invoice = Invoice::create([
                'patient_id' => $treatment->patient_id,
                'appointment_id' => null,
                'created_by' => $userId,
                'total_amount' => $price,
                'status' => InvoiceStatus::UNPAID,
            ]);

            $invoice->items()->create([
                'service_id' => null,
                'patient_treatment_id' => $treatment->id,
                'description' => $label,
                'price' => $price,
                'quantity' => 1,
            ]);

            $this->recomputeFinancialStatus($treatment);

            return $invoice->load(self::INVOICE_RELATIONS);
        });
    }

    public function createFromTreatment(PatientTreatment $treatment, string $userId): Invoice
    {
        return DB::transaction(function () use ($treatment, $userId) {
            $openInvoiceExists = Invoice::query()
                ->where('patient_id', $treatment->patient_id)
                ->whereIn('status', [InvoiceStatus::UNPAID, InvoiceStatus::PARTIAL])
                ->whereHas('items', fn ($q) => $q->where('patient_treatment_id', $treatment->id))
                ->exists();

            if ($openInvoiceExists) {
                throw ValidationException::withMessages([
                    'patient_treatment_id' => 'An unpaid invoice already exists for this treatment.',
                ]);
            }

            $alreadyBilled = InvoiceItem::query()
                ->where('patient_treatment_id', $treatment->id)
                ->exists();

            if ($alreadyBilled) {
                throw ValidationException::withMessages([
                    'patient_treatment_id' => 'This treatment has already been billed.',
                ]);
            }

            $invoice = $this->createInitialInvoiceForTreatment($treatment, $userId);

            if (! $invoice) {
                throw ValidationException::withMessages([
                    'patient_treatment_id' => 'Unable to create an invoice for this treatment.',
                ]);
            }

            return $invoice;
        });
    }

    public function update(Invoice $invoice, array $data): Invoice
    {
        return DB::transaction(function () use ($invoice, $data) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if ($lockedInvoice->status === InvoiceStatus::PAID) {
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

            if (isset($data['items'])) {
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
                $totalAmount = 0;
                $resolvedItems = [];

                foreach ($itemsData as $item) {
                    [$price, $description] = $this->resolveItemPriceAndDescription($item, $tenantId);
                    $totalAmount += $price * $item['quantity'];

                    $resolvedItems[] = [
                        'service_id' => $item['service_id'] ?? null,
                        'patient_treatment_id' => $item['patient_treatment_id'] ?? null,
                        'description' => $description,
                        'price' => $price,
                        'quantity' => $item['quantity'],
                    ];
                }

                $totalPayments = (float) $lockedInvoice->payments()->sum('amount')
                    + (float) $lockedInvoice->payments()->sum('deduct_amount');

                if (bccomp((string) $totalAmount, (string) $totalPayments, 2) === -1) {
                    throw ValidationException::withMessages([
                        'items' => 'New total cannot be less than paid amount.',
                    ]);
                }

                $lockedInvoice->items()->delete();

                foreach ($resolvedItems as $resolvedItem) {
                    $lockedInvoice->items()->create($resolvedItem);
                }

                $data['total_amount'] = $totalAmount;
            }

            $lockedInvoice->update($data);

            $this->recalculateStatus($lockedInvoice);

            return $lockedInvoice->load(self::INVOICE_RELATIONS);
        });
    }

    public function delete(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);
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

            $newTotal = (float) $remainingItems->sum(fn ($item) => (float) $item->price * (int) $item->quantity);
            $invoice->update(['total_amount' => $newTotal]);
            $this->recalculateStatus($invoice);
        }

        $this->recomputeFinancialStatus($treatment);
    }

    public function recalculateStatus(Invoice $invoice): void
    {
        $invoice->load('payments');
        $applied = $invoice->appliedCredits();
        $total = (float) $invoice->total_amount;

        $status = match (true) {
            $applied >= $total && $total > 0 => InvoiceStatus::PAID,
            $applied > 0 => InvoiceStatus::PARTIAL,
            default => InvoiceStatus::UNPAID,
        };

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
            $invoicePaid = (float) $invoice->payments->sum(function (Payment $payment) {
                return (float) $payment->amount + (float) ($payment->deduct_amount ?? 0);
            });
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
        $invoices = Invoice::where('patient_id', $patientId)->get();

        $totalBilled = $invoices->sum('total_amount');
        $totalPaid = Payment::whereIn('invoice_id', $invoices->pluck('id'))->sum('amount');
        $totalDeducted = Payment::whereIn('invoice_id', $invoices->pluck('id'))->sum('deduct_amount');

        return [
            'patient_id' => $patientId,
            'total_invoices_count' => $invoices->count(),
            'total_billed' => (float) $totalBilled,
            'total_paid' => (float) $totalPaid,
            'total_remaining' => (float) max(0, $totalBilled - $totalPaid - $totalDeducted),
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
                isset($item['price']) ? (float) $item['price'] : (float) $treatment->agreed_price,
                $item['description'] ?? $label,
            ];
        }

        if (! empty($item['service_id'])) {
            $service = Service::where('tenant_id', $tenantId)->find($item['service_id']);

            if (! $service) {
                throw ValidationException::withMessages([
                    'items' => 'One or more selected services are invalid.',
                ]);
            }

            return [
                $service->is_other ? (float) $item['price'] : (float) $service->default_price,
                $item['description'] ?? null,
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
}
