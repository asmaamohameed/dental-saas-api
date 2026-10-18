<?php

namespace App\Models;

use App\Enums\PaymentType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $invoice_id
 * @property PaymentType $type
 * @property string|null $invoice_item_id
 * @property string|null $refunded_payment_id
 * @property string $amount
 * @property string $deduct_amount
 * @property string|null $deduct_reason
 * @property string|null $reason
 * @property Carbon|null $paid_at
 * @property string $method
 * @property string $received_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property-read Invoice $invoice
 * @property-read InvoiceItem|null $invoiceItem
 * @property-read Payment|null $refundedPayment
 * @property-read User|null $receiver
 */
class Payment extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'invoice_id',
        'type',
        'invoice_item_id',
        'refunded_payment_id',
        'amount',
        'deduct_amount',
        'deduct_reason',
        'reason',
        'paid_at',
        'method',
        'received_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'amount' => 'decimal:2',
            'deduct_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Payments are append-only and cannot be updated.');
        });

        static::deleting(function (): void {
            throw new LogicException('Payments are append-only and cannot be deleted.');
        });
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    public function refundedPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refunded_payment_id');
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(self::class, 'refunded_payment_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
