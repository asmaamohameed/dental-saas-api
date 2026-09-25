<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $service_id
 * @property string $description
 * @property string $price
 * @property int $quantity
 * @property string $subtotal
 * @property-read Invoice $invoice
 * @property-read Service $service
 */
class InvoiceItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'invoice_id',
        'service_id',
        'patient_treatment_id',
        'description',
        'price',
        'quantity',
    ];

    protected $appends = [
        'subtotal',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    // Computed Attributes
    public function getSubtotalAttribute(): string
    {
        return bcmul((string) $this->price, (string) $this->quantity, 2);
    }

    // Relationships
    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<PatientTreatment, $this>
     */
    public function patientTreatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class);
    }
}
