<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\RefundReasonCode;
use App\Models\Invoice;
use App\Models\Refund;
use App\Support\Billing\InvoiceFinancials;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundService
{
    private const REFUND_RELATIONS = [
        'refundedBy',
        'invoice.patient',
        'invoice.creator',
        'invoice.items.patientTreatment.template',
        'invoice.payments',
        'invoice.refund',
    ];

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Refund::query()
            ->with([
                'refundedBy',
                'invoice.patient',
                'invoice.items.patientTreatment.template',
            ]);

        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('refunded_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @return array{total_amount: float, total_count: int, performers: list<array{id: string, name: string}>}
     */
    public function summary(array $filters = []): array
    {
        $query = Refund::query()->with('refundedBy');
        $this->applyFilters($query, $filters);

        $refunds = $query->get();

        $performerFilters = $filters;
        unset($performerFilters['refunded_by']);
        $performerQuery = Refund::query()->with('refundedBy');
        $this->applyFilters($performerQuery, $performerFilters);

        $performers = $performerQuery
            ->get()
            ->pluck('refundedBy')
            ->filter()
            ->unique('id')
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
            ])
            ->values()
            ->all();

        return [
            'total_amount' => round((float) $refunds->sum('amount'), 2),
            'total_count' => $refunds->count(),
            'performers' => $performers,
        ];
    }

    public function show(Refund $refund): Refund
    {
        return $refund->load(self::REFUND_RELATIONS);
    }

    public function create(Invoice $invoice, array $data, string $userId): Refund
    {
        try {
            return DB::transaction(function () use ($invoice, $data, $userId) {
                /** @var Invoice $lockedInvoice */
                $lockedInvoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
                $lockedInvoice->load('payments');

                if ($lockedInvoice->status === InvoiceStatus::REFUNDED) {
                    throw ValidationException::withMessages([
                        'invoice' => 'This invoice has already been refunded.',
                    ]);
                }

                if ($lockedInvoice->status === InvoiceStatus::CANCELLED) {
                    throw ValidationException::withMessages([
                        'invoice' => 'A cancelled invoice cannot be refunded.',
                    ]);
                }

                $amount = InvoiceFinancials::paidAmount($lockedInvoice);

                if (bccomp((string) $amount, '0', 2) !== 1) {
                    throw ValidationException::withMessages([
                        'invoice' => 'A refund can only be issued for an invoice that has recorded payments.',
                    ]);
                }

                $reasonCode = $data['reason_code'] instanceof RefundReasonCode
                    ? $data['reason_code']
                    : RefundReasonCode::from((string) $data['reason_code']);

                $reasonNote = null;
                if ($reasonCode->requiresNote()) {
                    $reasonNote = trim((string) ($data['reason_note'] ?? ''));
                    if ($reasonNote === '') {
                        throw ValidationException::withMessages([
                            'reason_note' => 'A note is required when the refund reason is Other.',
                        ]);
                    }
                    if (mb_strlen($reasonNote) > RefundReasonCode::NOTE_MAX_LENGTH) {
                        throw ValidationException::withMessages([
                            'reason_note' => 'The refund note may not be greater than '.RefundReasonCode::NOTE_MAX_LENGTH.' characters.',
                        ]);
                    }
                }

                $method = $data['method'] ?? InvoiceFinancials::defaultRefundMethod($lockedInvoice);

                /** @var Refund $refund */
                $refund = $lockedInvoice->refund()->create([
                    'amount' => $amount,
                    'reason_code' => $reasonCode,
                    'reason_note' => $reasonNote,
                    'method' => $method,
                    'refunded_by' => $userId,
                    'refunded_at' => now(),
                ]);

                $lockedInvoice->update(['status' => InvoiceStatus::REFUNDED]);

                return $refund->load(self::REFUND_RELATIONS);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'invoice' => 'This invoice has already been refunded.',
            ]);
        }
    }

    private function applyFilters(object $query, array $filters): void
    {
        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $query->dateRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);
        }

        if (! empty($filters['refunded_by'])) {
            $query->where('refunded_by', $filters['refunded_by']);
        }

        if (! empty($filters['method'])) {
            $query->where('method', $filters['method']);
        }

        if (! empty($filters['reason_code'])) {
            $query->where('reason_code', $filters['reason_code']);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        if (! empty($filters['patient_id'])) {
            $query->whereHas('invoice', function ($invoiceQuery) use ($filters) {
                $invoiceQuery->where('patient_id', $filters['patient_id']);
            });
        }
    }
}
