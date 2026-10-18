<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Billing\InvoiceFinancials;
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
 * @property-read Collection<int, Refund> $refunds
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

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    // Computed Attributes
    public function getRemainingAmountAttribute(): float
    {
        return InvoiceFinancials::remainingAmount($this);
    }

    public function appliedCredits(): float
    {
        return InvoiceFinancials::appliedCredits($this);
    }

    public function paidAmount(): float
    {
        return InvoiceFinancials::paidAmount($this);
    }

    public function deductedAmount(): float
    {
        return InvoiceFinancials::deductedAmount($this);
    }

    public function refundedAmount(): float
    {
        return InvoiceFinancials::refundedAmount($this);
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
        $escapedSearch = addcslashes(trim($search), '%_\\');
        $pattern = "%{$escapedSearch}%";

        return $query->where(function (Builder $q) use ($pattern) {
            $q->where('id', 'ilike', $pattern)
                ->orWhereHas('patient', function (Builder $patientQuery) use ($pattern) {
                    $patientQuery->where('full_name', 'ilike', $pattern);
                });
        });
    }
}
