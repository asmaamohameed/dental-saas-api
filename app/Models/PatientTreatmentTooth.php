<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_treatment_id
 * @property string $tooth_number
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read PatientTreatment $treatment
 */
class PatientTreatmentTooth extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'patient_treatment_id',
        'tooth_number',
    ];

    /**
     * @return BelongsTo<PatientTreatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class, 'patient_treatment_id');
    }
}
