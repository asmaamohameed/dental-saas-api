<?php

namespace App\Models;

use App\Enums\TreatmentCategory;
use App\Enums\TreatmentScope;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $default_price
 * @property TreatmentCategory $category
 * @property TreatmentScope $scope
 * @property-read Collection<int, PatientTreatment> $patientTreatments
 */
class TreatmentTemplate extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'name_ar',
        'name_en',
        'category',
        'scope',
        'description',
        'default_price',
        'estimated_duration_minutes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => TreatmentCategory::class,
            'scope' => TreatmentScope::class,
            'default_price' => 'decimal:2',
            'estimated_duration_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PatientTreatment, $this>
     */
    public function patientTreatments(): HasMany
    {
        return $this->hasMany(PatientTreatment::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class, 'service_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
