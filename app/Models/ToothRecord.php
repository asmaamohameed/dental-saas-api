<?php

namespace App\Models;

use App\Enums\ToothTreatmentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ToothRecord extends Model
{
    use Auditable, BelongsToTenant, HasFactory,HasUuids;

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

    /**
     * Returns the most recent record per tooth for a given patient.
     * Uses a subquery on max(created_at) grouped by tooth_number.
     *
     * @return Collection<int, ToothRecord>
     */
    public static function latestPerTooth(string $patientId)
    {
        return self::where('patient_id', $patientId)
            ->whereIn('id', function ($query) use ($patientId) {
                $query->select('id')
                    ->from(function ($sub) use ($patientId) {
                        $sub->from('tooth_records')
                            ->selectRaw('id, ROW_NUMBER() OVER (PARTITION BY tooth_number ORDER BY created_at DESC, id DESC) as rn')
                            ->where('patient_id', $patientId);
                    }, 'ranked')
                    ->where('rn', 1);
            })
            ->get();
    }
}
