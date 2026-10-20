<?php

namespace App\Models;

use App\Enums\RefundReasonCode;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $invoice_id
 * @property string|null $invoice_item_id
 * @property string $amount
 * @property RefundReasonCode $reason_code
 * @property string|null $reason_note
 * @property string $method
 * @property string|null $refunded_by
 * @property Carbon $refunded_at
 * @property-read Invoice $invoice
 * @property-read InvoiceItem|null $invoiceItem
 * @property-read User|null $refundedBy
 */
class Refund extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'invoice_id',
        'invoice_item_id',
        'amount',
        'reason_code',
        'reason_note',
        'method',
        'refunded_by',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reason_code' => RefundReasonCode::class,
            'refunded_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function refundedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'refunded_by');
    }

    public function scopeDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->whereDate('refunded_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('refunded_at', '<=', $to);
        }

        return $query;
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $escapedSearch = addcslashes(trim($search), '%_\\');
        $pattern = "%{$escapedSearch}%";

        return $query->where(function (Builder $q) use ($pattern) {
            $q->where('invoice_id', 'ilike', $pattern)
                ->orWhereHas('invoice.patient', function (Builder $patientQuery) use ($pattern) {
                    $patientQuery->where('full_name', 'ilike', $pattern);
                });
        });
    }
}
