<?php

namespace App\Models;

use App\Enums\TreatmentSessionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
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
 * @property string $patient_treatment_id
 * @property string|null $appointment_id
 * @property string|null $dentist_id
 * @property Carbon $session_date
 * @property TreatmentSessionStatus $status
 * @property string|null $notes
 * @property bool $stock_applied
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PatientTreatment $treatment
 * @property-read Appointment|null $appointment
 * @property-read User|null $dentist
 * @property-read Collection<int, TreatmentSessionStep> $steps
 * @property-read Collection<int, PatientTreatmentComponent> $components
 */
class TreatmentSession extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'patient_treatment_id',
        'appointment_id',
        'dentist_id',
        'session_date',
        'status',
        'notes',
        'stock_applied',
    ];

    protected function casts(): array
    {
        return [
            'session_date' => 'datetime',
            'status' => TreatmentSessionStatus::class,
            'stock_applied' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PatientTreatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class, 'patient_treatment_id');
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
    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_id');
    }

    /**
     * @return HasMany<TreatmentSessionStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(TreatmentSessionStep::class)->orderBy('created_at');
    }

    /**
     * @return HasMany<PatientTreatmentComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(PatientTreatmentComponent::class);
    }
}
