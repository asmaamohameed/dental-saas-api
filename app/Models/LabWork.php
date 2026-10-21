<?php

namespace App\Models;

use App\Enums\LabWorkStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $patient_id
 * @property string $lab_id
 * @property string $description
 * @property string|null $notes
 * @property Carbon $sent_on
 * @property Carbon $expected_on
 * @property Carbon|null $received_on
 * @property LabWorkStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Patient $patient
 * @property-read Lab $lab
 */
class LabWork extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'patient_id',
        'lab_id',
        'description',
        'notes',
        'sent_on',
        'expected_on',
        'received_on',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'sent_on' => 'date',
            'expected_on' => 'date',
            'received_on' => 'date',
            'status' => LabWorkStatus::class,
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
     * @return BelongsTo<Lab, $this>
     */
    public function lab(): BelongsTo
    {
        return $this->belongsTo(Lab::class);
    }
}
