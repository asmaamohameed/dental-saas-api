<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ToothRecord extends Model
{
    use HasUuids, BelongsToTenant, Auditable;

    protected $fillable = [
        'tenant_id',
        'patient_id',
        'appointment_id',
        'recorded_by',
        'tooth_number',
        'condition',
        'treatment_status',
        'notes',
    ];

    // Relationships
    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    public function recordedBy()
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
