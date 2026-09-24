<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $amount
 * @property string $deduct_amount
 * @property string|null $deduct_reason
 * @property Carbon|null $paid_at
 * @property string $method
 * @property string $received_by
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property-read Invoice $invoice
 * @property-read User|null $receiver
 */
class Payment extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'amount',
        'deduct_amount',
        'deduct_reason',
        'paid_at',
        'method',
        'received_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'deduct_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    // Relationships
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
