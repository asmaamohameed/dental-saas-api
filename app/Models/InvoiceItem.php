<?php

namespace App\Models;

use App\Enums\PaymentType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string|null $patient_treatment_id
 * @property string|null $patient_treatment_tooth_id
 * @property string|null $appointment_id
 * @property Carbon|null $voided_at
 * @property string|null $void_reason
 * @property string|null $voided_by
 * @property string|null $description
 * @property string $price
 * @property int $quantity
 * @property string $subtotal
 * @property-read Invoice $invoice
 * @property-read PatientTreatment|null $patientTreatment
 * @property-read PatientTreatmentTooth|null $tooth
 * @property-read Appointment|null $appointment
 * @property-read User|null $voidedByUser
 */
class InvoiceItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'patient_treatment_id',
        'patient_treatment_tooth_id',
        'appointment_id',
        'description',
        'price',
        'quantity',
        'voided_at',
        'void_reason',
        'voided_by',
    ];

    protected $appends = [
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'voided_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function getSubtotalAttribute(): string
    {
        return bcmul((string) $this->price, (string) $this->quantity, 2);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<PatientTreatment, $this>
     */
    public function patientTreatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class);
    }

    /**
     * @return BelongsTo<PatientTreatmentTooth, $this>
     */
    public function tooth(): BelongsTo
    {
        return $this->belongsTo(PatientTreatmentTooth::class, 'patient_treatment_tooth_id');
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * Refund rows that name this item. Clinical status is not stored here.
     *
     * @return HasMany<Payment, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_item_id')->where('type', PaymentType::Refund->value);
    }

    public function refundedAmount(): float
    {
        if ($this->relationLoaded('refunds')) {
            $sum = '0.00';

            foreach ($this->refunds as $refund) {
                $sum = bcadd($sum, bcadd((string) ($refund->amount ?? 0), '0', 2), 2);
            }

            return max(0, (float) $sum);
        }

        return max(0, (float) $this->refunds()->sum('amount'));
    }
}
