<?php

namespace App\Models;

use App\Enums\ConsentStatus;
use App\Enums\FinancialStatus;
use App\Enums\PatientTreatmentStatus;
use App\Enums\TreatmentPriority;
use App\Enums\TreatmentType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string|null $treatment_plan_id
 * @property string|null $treatment_template_id
 * @property int $treatment_template_version
 * @property string $agreed_price
 * @property PatientTreatmentStatus $clinical_status
 * @property string|null $cancellation_reason
 * @property FinancialStatus $financial_status
 * @property ConsentStatus $consent_status
 * @property string|null $consent_document_ref
 * @property string|null $parent_treatment_id
 * @property TreatmentType $treatment_type
 * @property string|null $dentist_id
 * @property string|null $diagnosis
 * @property TreatmentPriority $priority
 * @property string|null $notes
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $created_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Patient $patient
 * @property-read TreatmentPlan|null $plan
 * @property-read TreatmentTemplate|null $template
 * @property-read User|null $dentist
 * @property-read User|null $creator
 * @property-read PatientTreatment|null $parentTreatment
 * @property-read Collection<int, PatientTreatment> $retreatments
 * @property-read Collection<int, PatientTreatmentTooth> $teeth
 * @property-read Collection<int, TreatmentSession> $sessions
 * @property-read Collection<int, InvoiceItem> $invoiceItems
 */
class PatientTreatment extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'treatment_plan_id',
        'treatment_template_id',
        'treatment_template_version',
        'agreed_price',
        'clinical_status',
        'cancellation_reason',
        'financial_status',
        'consent_status',
        'consent_document_ref',
        'parent_treatment_id',
        'treatment_type',
        'dentist_id',
        'diagnosis',
        'priority',
        'notes',
        'started_at',
        'completed_at',
        'cancelled_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'treatment_template_version' => 'integer',
            'agreed_price' => 'decimal:2',
            'clinical_status' => PatientTreatmentStatus::class,
            'financial_status' => FinancialStatus::class,
            'consent_status' => ConsentStatus::class,
            'treatment_type' => TreatmentType::class,
            'priority' => TreatmentPriority::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Patient, $this>
     */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /**
     * @return BelongsTo<TreatmentPlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(TreatmentPlan::class, 'treatment_plan_id');
    }

    /**
     * @return BelongsTo<TreatmentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TreatmentTemplate::class, 'treatment_template_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<PatientTreatment, $this>
     */
    public function parentTreatment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_treatment_id');
    }

    /**
     * @return HasMany<PatientTreatment, $this>
     */
    public function retreatments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_treatment_id');
    }

    /**
     * @return HasMany<PatientTreatmentTooth, $this>
     */
    public function teeth(): HasMany
    {
        return $this->hasMany(PatientTreatmentTooth::class);
    }

    /**
     * @return HasMany<TreatmentSession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(TreatmentSession::class)->orderBy('session_date');
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

    public function toothNumbers(): array
    {
        return $this->teeth->pluck('tooth_number')->map(fn ($n) => (string) $n)->values()->all();
    }
}
