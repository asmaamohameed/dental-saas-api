<?php

namespace App\Models;

use App\Enums\PatientTreatmentVisitStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property PatientTreatmentVisitStatus $status
 * @property int $visit_order
 * @property Carbon|null $scheduled_date
 * @property Carbon|null $completed_date
 * @property Carbon|null $completed_at
 */
class PatientTreatmentVisit extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'patient_treatment_id',
        'visit_order',
        'name',
        'description',
        'status',
        'scheduled_date',
        'completed_date',
        'completed_at',
        'dentist_id',
        'appointment_id',
        'status_reason',
        'visit_price',
    ];

    protected function casts(): array
    {
        return [
            'visit_order' => 'integer',
            'status' => PatientTreatmentVisitStatus::class,
            'scheduled_date' => 'datetime',
            'completed_date' => 'datetime',
            'completed_at' => 'datetime',
            'visit_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<PatientTreatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class, 'patient_treatment_id');
    }

    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class, 'appointment_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(PatientTreatmentComponent::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }
}
