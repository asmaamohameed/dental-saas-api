<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Enums\PatientTreatmentVisitStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PatientTreatment;
use App\Models\PatientTreatmentVisit;
use App\Models\Payment;
use App\Models\Service;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Invoice::query()
            ->with(['patient', 'creator'])
            ->withSum('payments as paid_amount', 'amount');

        if (!empty($filters['status'])) {
            $query->byStatus($filters['status']);
        }

        if (!empty($filters['patient_id'])) {
            $query->byPatient($filters['patient_id']);
        }

        if (!empty($filters['date_from']) || !empty($filters['date_to'])) {
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
                    'patient_treatment_visit_id' => $item['patient_treatment_visit_id'] ?? null,
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

            return $invoice->load(['patient', 'appointment', 'creator', 'items.service', 'items.patientTreatment.template', 'items.patientTreatmentVisit', 'payments']);
        });
    }

    public function show(Invoice $invoice): Invoice
    {
        return $invoice->load(['patient', 'appointment', 'creator', 'items.service', 'items.patientTreatment.template', 'items.patientTreatmentVisit', 'payments.receiver']);
    }

    public function createInitialInvoiceForTreatment(PatientTreatment $treatment, string $userId): ?Invoice
    {
        return DB::transaction(function () use ($treatment, $userId) {
            $treatment->load(['visits', 'template', 'patient']);

            if ($treatment->visits->isEmpty()) {
                return null;
            }

            $alreadyExists = InvoiceItem::query()
                ->where('patient_treatment_id', $treatment->id)
                ->exists();

            if ($alreadyExists) {
                return null;
            }

            return $this->createTreatmentInvoice($treatment, $userId, $treatment->visits->sortBy('visit_order')->values());
        });
    }

    public function createFromTreatment(PatientTreatment $treatment, string $userId): Invoice
    {
        return DB::transaction(function () use ($treatment, $userId) {
            $treatment->load(['visits', 'template', 'patient']);

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

            $billedVisitIds = InvoiceItem::query()
                ->where('patient_treatment_id', $treatment->id)
                ->whereNotNull('patient_treatment_visit_id')
                ->pluck('patient_treatment_visit_id');

            $visitsToBill = $treatment->visits
                ->filter(function (PatientTreatmentVisit $visit) use ($billedVisitIds) {
                    $status = $visit->status instanceof PatientTreatmentVisitStatus
                        ? $visit->status->value
                        : (string) $visit->status;

                    return $status === PatientTreatmentVisitStatus::COMPLETED->value
                        && !$billedVisitIds->contains($visit->id);
                })
                ->sortBy('visit_order')
                ->values();

            if ($visitsToBill->isEmpty()) {
                throw ValidationException::withMessages([
                    'patient_treatment_id' => 'No completed visits are available to bill.',
                ]);
            }

            return $this->createTreatmentInvoice($treatment, $userId, $visitsToBill);
        });
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PatientTreatmentVisit>  $visits
     */
    private function createTreatmentInvoice(PatientTreatment $treatment, string $userId, $visits): Invoice
    {
        $totalVisits = max(1, (int) ($treatment->total_visits ?: $treatment->visits->count()));
        $fallbackVisitPrice = (float) $treatment->actual_price / $totalVisits;

        $resolvedItems = [];
        $totalAmount = 0.0;

        foreach ($visits as $visit) {
            $price = $visit->visit_price !== null
                ? (float) $visit->visit_price
                : $fallbackVisitPrice;
            $totalAmount += $price;

            $resolvedItems[] = [
                'service_id' => null,
                'patient_treatment_id' => $treatment->id,
                'patient_treatment_visit_id' => $visit->id,
                'description' => "Visit {$visit->visit_order}: {$visit->name}",
                'price' => $price,
                'quantity' => 1,
            ];
        }

        $invoice = Invoice::create([
            'patient_id' => $treatment->patient_id,
            'appointment_id' => null,
            'created_by' => $userId,
            'total_amount' => $totalAmount,
            'status' => InvoiceStatus::UNPAID,
        ]);

        foreach ($resolvedItems as $resolvedItem) {
            $invoice->items()->create($resolvedItem);
        }

        return $invoice->load(['patient', 'appointment', 'creator', 'items.service', 'items.patientTreatment.template', 'items.patientTreatmentVisit', 'payments']);
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
                        fn (array $item) => !empty($item['patient_treatment_id'])
                    );

                    if (!$onlyTreatmentItems) {
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
                        'patient_treatment_visit_id' => $item['patient_treatment_visit_id'] ?? null,
                        'description' => $description,
                        'price' => $price,
                        'quantity' => $item['quantity'],
                    ];
                }

                $totalPayments = (float) $lockedInvoice->payments()->sum('amount');

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

            return $lockedInvoice->load(['patient', 'appointment', 'creator', 'items.service', 'items.patientTreatment.template', 'items.patientTreatmentVisit', 'payments']);
        });
    }

    public function delete(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice) {
            $lockedInvoice = Invoice::lockForUpdate()->find($invoice->id);

            if ($lockedInvoice->payments()->exists()) {
                throw new InvoiceHasPaymentsException(
                    'Cannot cancel an invoice that has recorded payments.'
                );
            }

            $lockedInvoice->update(['status' => InvoiceStatus::CANCELLED->value]);

            return (bool) $lockedInvoice->delete();
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

            if (!$invoice || $invoice->status !== InvoiceStatus::UNPAID) {
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
    }

    public function recalculateStatus(Invoice $invoice): void
    {
        $paid = (float) $invoice->payments()->sum('amount');
        $total = (float) $invoice->total_amount;

        $status = match (true) {
            $paid >= $total && $total > 0 => InvoiceStatus::PAID,
            $paid > 0 => InvoiceStatus::PARTIAL,
            default => InvoiceStatus::UNPAID,
        };

        if ($invoice->status !== $status) {
            $invoice->update(['status' => $status]);
        }
    }

    public function getPatientSummary(string $patientId): array
    {
        $invoices = Invoice::where('patient_id', $patientId)->get();

        $totalBilled = $invoices->sum('total_amount');
        $totalPaid = Payment::whereIn('invoice_id', $invoices->pluck('id'))->sum('amount');

        return [
            'patient_id' => $patientId,
            'total_invoices_count' => $invoices->count(),
            'total_billed' => (float) $totalBilled,
            'total_paid' => (float) $totalPaid,
            'total_remaining' => (float) max(0, $totalBilled - $totalPaid),
        ];
    }

    /**
     * @return array{0: float, 1: string|null}
     */
    private function resolveItemPriceAndDescription(array $item, string $tenantId): array
    {
        if (!empty($item['patient_treatment_id'])) {
            $treatment = PatientTreatment::with('template')
                ->where('tenant_id', $tenantId)
                ->find($item['patient_treatment_id']);

            if (!$treatment) {
                throw ValidationException::withMessages([
                    'items' => 'One or more selected treatments are invalid.',
                ]);
            }

            $label = $treatment->template?->name_en ?: $treatment->template?->name_ar ?: 'Patient treatment';

            return [
                isset($item['price']) ? (float) $item['price'] : (float) $treatment->actual_price,
                $item['description'] ?? $label,
            ];
        }

        $service = Service::where('tenant_id', $tenantId)->find($item['service_id'] ?? null);

        if (!$service) {
            throw ValidationException::withMessages([
                'items' => 'One or more selected services are invalid.',
            ]);
        }

        return [
            $service->is_other ? (float) $item['price'] : (float) $service->default_price,
            $item['description'] ?? null,
        ];
    }
}
