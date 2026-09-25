<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string|null $appointment_id
 * @property string|null $created_by
 * @property string $total_amount
 * @property float $remaining_amount
 * @property InvoiceStatus $status
 * @property-read Patient $patient
 * @property-read Appointment|null $appointment
 * @property-read User|null $creator
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Collection<int, Payment> $payments
 */
class Invoice extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'patient_id',
        'appointment_id',
        'created_by',
        'total_amount',
        'status',
    ];

    protected $appends = [
        'remaining_amount',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
        ];
    }

    // Relationships
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // Computed Attributes
    public function getRemainingAmountAttribute(): float
    {
        $applied = $this->appliedCredits();

        return max(0, (float) $this->total_amount - $applied);
    }

    public function appliedCredits(): float
    {
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->sum(function (Payment $payment) {
                return (float) $payment->amount + (float) ($payment->deduct_amount ?? 0);
            });
        }

        if (array_key_exists('paid_amount', $this->attributes)) {
            return (float) $this->attributes['paid_amount']
                + (float) ($this->attributes['deducted_amount'] ?? 0);
        }

        return (float) $this->payments()->sum('amount')
            + (float) $this->payments()->sum('deduct_amount');
    }

    public function paidAmount(): float
    {
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->sum('amount');
        }

        if (array_key_exists('paid_amount', $this->attributes)) {
            return (float) $this->attributes['paid_amount'];
        }

        return (float) $this->payments()->sum('amount');
    }

    public function deductedAmount(): float
    {
        if ($this->relationLoaded('payments')) {
            return (float) $this->payments->sum(fn (Payment $payment) => (float) ($payment->deduct_amount ?? 0));
        }

        if (array_key_exists('deducted_amount', $this->attributes)) {
            return (float) $this->attributes['deducted_amount'];
        }

        return (float) $this->payments()->sum('deduct_amount');
    }

    // Scopes
    public function scopeNewestFirst(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->orderByDesc("{$table}.created_at")
            ->orderByDesc("{$table}.id");
    }

    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeByPatient(Builder $query, string $patientId): Builder
    {
        return $query->where('patient_id', $patientId);
    }

    public function scopeDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query;
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $escapedSearch = addcslashes($search, '%_\\');

        return $query->where(function (Builder $q) use ($escapedSearch) {
            $q->where('id', 'LIKE', "%{$escapedSearch}%")
                ->orWhereHas('patient', function (Builder $patientQuery) use ($escapedSearch) {
                    $patientQuery->where('full_name', 'LIKE', "%{$escapedSearch}%");
                });
        });
    }
}
