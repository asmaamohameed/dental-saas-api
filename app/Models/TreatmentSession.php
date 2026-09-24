<?php

namespace App\Models;

use App\Enums\TreatmentSessionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function dentist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dentist_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TreatmentSessionStep::class)->orderBy('created_at');
    }

    public function components(): HasMany
    {
        return $this->hasMany(PatientTreatmentComponent::class);
    }
}
