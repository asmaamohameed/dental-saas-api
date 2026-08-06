<?php

namespace App\Services;

use App\Models\Invoice;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Invoice::query()->with(['patient', 'creator']);

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
                'status' => 'unpaid',
            ]);

            foreach ($itemsData as $item) {
                $invoice->items()->create([
                    'service_id' => $item['service_id'],
                    'description' => $item['description'] ?? null,
                    'price' => $item['price'],
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
                    $totalAmount += $item['price'] * $item['quantity'];
                    $invoice->items()->create([
                        'service_id' => $item['service_id'],
                        'description' => $item['description'] ?? null,
                        'price' => $item['price'],
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
            $invoice->payments()->delete();

            return (bool) $invoice->delete();
        });
    }

    public function recalculateStatus(Invoice $invoice): void
    {
        $paid = (float) $invoice->payments()->sum('amount');
        $total = (float) $invoice->total_amount;

        if ($paid >= $total && $total > 0) {
            $status = 'paid';
        } elseif ($paid > 0) {
            $status = 'partial';
        } else {
            $status = 'unpaid';
        }

        if ($invoice->status !== $status) {
            $invoice->update(['status' => $status]);
        }
    }

    public function getPatientSummary(string $patientId): array
    {
        $invoices = Invoice::where('patient_id', $patientId)->get();

        $totalBilled = $invoices->sum('total_amount');
        $totalPaid = $invoices->sum(fn ($invoice) => $invoice->payments()->sum('amount'));

        return [
            'patient_id' => $patientId,
            'total_invoices_count' => $invoices->count(),
            'total_billed' => (float) $totalBilled,
            'total_paid' => (float) $totalPaid,
            'total_remaining' => (float) max(0, $totalBilled - $totalPaid),
        ];
    }
}
