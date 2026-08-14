<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Service;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

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

            $totalAmount = 0;
            foreach ($itemsData as $item) {
                $totalAmount += $item['price'] * $item['quantity'];
            }

            $invoice = Invoice::create([
                ...$data,
                'created_by' => $userId,
                'total_amount' => $totalAmount,
                'status' => InvoiceStatus::UNPAID,
            ]);

            foreach ($itemsData as $item) {
                $service = Service::find($item['service_id']);

                $price = $service->is_other
                    ? (float) $item['price']
                    : (float) $service->default_price;

                $totalAmount += $price * $item['quantity'];

                $invoice->items()->create([
                    'service_id' => $item['service_id'],
                    'description' => $item['description'] ?? null,
                    'price' => $price,
                    'quantity' => $item['quantity'],
                ]);
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
            if (isset($data['items'])) {
                $itemsData = $data['items'];
                unset($data['items']);

                $invoice->items()->delete();

                $totalAmount = 0;
                foreach ($itemsData as $item) {
                    $service = Service::find($item['service_id']);

                    $price = $service->is_other
                        ? (float) $item['price']
                        : (float) $service->default_price;

                    $totalAmount += $price * $item['quantity'];
                    $invoice->items()->create([
                        'service_id' => $item['service_id'],
                        'description' => $item['description'] ?? null,
                        'price' => $price,
                        'quantity' => $item['quantity'],
                    ]);
                }

                $data['total_amount'] = $totalAmount;
            }

            $invoice->update($data);

            $this->recalculateStatus($invoice);

            return $invoice->load(['patient', 'appointment', 'creator', 'items.service', 'payments']);
        });
    }

    public function delete(Invoice $invoice): bool
    {
        return DB::transaction(function () use ($invoice) {
            if ($invoice->payments()->exists()) {
                throw new InvoiceHasPaymentsException(
                    'Cannot cancel an invoice that has recorded payments.'
                );
            }

            $invoice->update(['status' => InvoiceStatus::CANCELLED->value]);

            return (bool) $invoice->delete();
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

        if ($paid >= $total && $total > 0) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partial';
        } else {
            $status = 'unpaid';
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
