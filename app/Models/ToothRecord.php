<?php

namespace App\Models;

use App\Enums\ToothTreatmentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToothRecord extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'patient_id',
        'appointment_id',
        'recorded_by',
        'tooth_number',
        'condition',
        'treatment_status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'tooth_number' => 'integer',
            'treatment_status' => ToothTreatmentStatus::class,
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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
