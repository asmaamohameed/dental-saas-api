<?php

namespace App\Models;

use App\Billing\Domain\InvoiceBalance;
use App\Billing\Queries\InvoiceItemStateSql;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentType;
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
 * @property string|null $treatment_plan_id
 * @property string|null $created_by
 * @property string $total_amount
 * @property float $remaining_amount
 * @property InvoiceStatus $status
 * @property-read Patient $patient
 * @property-read Appointment|null $appointment
 * @property-read TreatmentPlan|null $treatmentPlan
 * @property-read User|null $creator
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, Payment> $refunds
 */
class Invoice extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    private ?InvoiceBalance $balanceCache = null;

    private ?InvoiceBalance $itemBalanceCache = null;

    protected $fillable = [
        'patient_id',
        'appointment_id',
        'treatment_plan_id',
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

    /**
     * @return BelongsTo<TreatmentPlan, $this>
     */
    public function treatmentPlan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class);
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
     * Cash payments only. Refund rows live on the same table and are not payments.
     *
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->where('type', PaymentType::Payment->value);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class)->where('type', PaymentType::Refund->value);
    }

    public function forgetBalance(): void
    {
        $this->balanceCache = null;
        $this->itemBalanceCache = null;
        $this->unsetRelation('payments');
        $this->unsetRelation('refunds');

        $attributes = $this->getAttributes();
        unset($attributes['paid_amount'], $attributes['deducted_amount'], $attributes['refunded_amount']);
        $this->setRawAttributes($attributes, true);
    }

    /**
     * Balance is cached on the instance. refresh() reloads attributes and relations
     * but does not reset that cache, so a later remaining/net read would stay stale.
     */
    public function refresh(): static
    {
        parent::refresh();
        $this->balanceCache = null;
        $this->itemBalanceCache = null;

        return $this;
    }

    /**
     * Delegates formulas to InvoiceBalance.
     * Item-derived total and performedTotal are queried only when $withItemDerived is true,
     * so list screens that read remaining_amount do not scan invoice_items.
     *
     * @return array{
     *     gross_paid: string,
     *     refunded: string,
     *     deducted: string,
     *     net_paid: string,
     *     settled: string,
     *     total: string,
     *     remaining: string,
     *     refund_due: string,
     *     has_refund_due: bool,
     *     performed_total?: string,
     *     due_performed?: string
     * }
     */
    public function balance(bool $withItemDerived = false): array
    {
        return $this->invoiceBalance($withItemDerived)->toArray();
    }

    public function invoiceBalance(bool $withItemDerived = false): InvoiceBalance
    {
        if ($withItemDerived) {
            return $this->itemBalanceCache ??= $this->buildBalance(true);
        }

        return $this->balanceCache ??= $this->buildBalance(false);
    }

    private function buildBalance(bool $withItemDerived): InvoiceBalance
    {
        $total = $this->money($this->total_amount);
        $performedTotal = null;

        if ($withItemDerived) {
            $derived = (new InvoiceItemStateSql)->totalsForInvoice((string) $this->id);
            $total = $derived['total'];
            $performedTotal = $derived['performed_total'];
        }

        return InvoiceBalance::fromRawSums(
            $this->aggregate('payments', 'paid_amount', 'amount'),
            $this->aggregate('refunds', 'refunded_amount', 'amount'),
            $this->aggregate('payments', 'deducted_amount', 'deduct_amount'),
            $total,
            $performedTotal,
        );
    }

    public function getRemainingAmountAttribute(): float
    {
        return (float) $this->balance()['remaining'];
    }

    public function appliedCredits(): float
    {
        return $this->settledAmount();
    }

    public function paidAmount(): float
    {
        return (float) $this->balance()['gross_paid'];
    }

    public function netPaid(): float
    {
        return (float) $this->balance()['net_paid'];
    }

    public function refundedAmount(): float
    {
        return (float) $this->balance()['refunded'];
    }

    public function deductedAmount(): float
    {
        return (float) $this->balance()['deducted'];
    }

    public function settledAmount(): float
    {
        return (float) $this->balance()['settled'];
    }

    private function aggregate(string $relation, string $attribute, string $column): string
    {
        if ($this->relationLoaded($relation)) {
            $sum = '0.00';

            foreach ($this->getRelation($relation) as $row) {
                $sum = bcadd($sum, $this->money($row->{$column} ?? 0), 2);
            }

            return $sum;
        }

        if (array_key_exists($attribute, $this->attributes)) {
            return $this->money($this->attributes[$attribute]);
        }

        $query = $relation === 'refunds' ? $this->refunds() : $this->payments();

        return $this->money($query->sum($column));
    }

    private function money(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return bcadd((string) $value, '0', 2);
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
