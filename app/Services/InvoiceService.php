<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Models\Invoice;
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
                $service = Service::where('tenant_id', $tenantId)->find($item['service_id']);

                if (! $service) {
                    throw ValidationException::withMessages([
                        'items' => 'One or more selected services are invalid.',
                    ]);
                }

                $price = $service->is_other ? (float) $item['price'] : (float) $service->default_price;
                $totalAmount += $price * $item['quantity'];

                $resolvedItems[] = [
                    'service_id' => $item['service_id'],
                    'description' => $item['description'] ?? null,
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

            return $invoice->load(['patient', 'appointment', 'creator', 'items.service', 'payments']);
        });
    }

    public function show(Invoice $invoice): Invoice
    {
        return $invoice->load(['patient', 'appointment', 'creator', 'items.service', 'payments.receiver']);
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

            if (isset($data['items']) && $lockedInvoice->status === InvoiceStatus::PARTIAL) {
                throw ValidationException::withMessages([
                    'items' => 'Partially paid invoices cannot be updated.',
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

                $tenantId = app(CurrentTenant::class)->id();
                $totalAmount = 0;
                $resolvedItems = [];

                foreach ($itemsData as $item) {
                    $service = Service::where('tenant_id', $tenantId)->find($item['service_id']);

                    if (! $service) {
                        throw ValidationException::withMessages([
                            'items' => 'One or more selected services are invalid.',
                        ]);
                    }

                    $price = $service->is_other ? (float) $item['price'] : (float) $service->default_price;
                    $totalAmount += $price * $item['quantity'];

                    $resolvedItems[] = [
                        'service_id' => $item['service_id'],
                        'description' => $item['description'] ?? null,
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

            return $lockedInvoice->load(['patient', 'appointment', 'creator', 'items.service', 'payments']);
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
}
