<?php

namespace App\Models;

use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentPriority;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property PatientTreatmentStatus $status
 * @property TreatmentPriority $priority
 * @property int $total_visits
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 */
class PatientTreatment extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'treatment_template_id',
        'doctor_id',
        'tooth_number',
        'diagnosis',
        'status',
        'priority',
        'total_visits',
        'actual_price',
        'notes',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => PatientTreatmentStatus::class,
            'priority' => TreatmentPriority::class,
            'total_visits' => 'integer',
            'actual_price' => 'decimal:2',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<TreatmentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TreatmentTemplate::class, 'treatment_template_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<PatientTreatmentVisit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(PatientTreatmentVisit::class)->orderBy('visit_order');
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function scopeForPatient(Builder $query, string $patientId): Builder
    {
        return $query->where('patient_id', $patientId);
    }
}
