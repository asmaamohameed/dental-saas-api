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

class PatientTreatmentVisit extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'patient_treatment_id',
        'visit_order',
        'name',
        'description',
        'status',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'visit_order' => 'integer',
            'status' => PatientTreatmentVisitStatus::class,
            'completed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PatientTreatment, $this>
     */
    public function treatment(): BelongsTo
    {
        return $this->belongsTo(PatientTreatment::class, 'patient_treatment_id');
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
