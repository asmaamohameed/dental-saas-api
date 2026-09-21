<?php

namespace App\Models;

use App\Enums\TreatmentCategory;
use App\Enums\TreatmentVisitType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentTemplate extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'name_ar',
        'name_en',
        'category',
        'description',
        'default_price',
        'estimated_duration_minutes',
        'visit_type',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'category' => TreatmentCategory::class,
            'default_price' => 'decimal:2',
            'estimated_duration_minutes' => 'integer',
            'visit_type' => TreatmentVisitType::class,
            'is_active' => 'boolean',
        ];
    }

    public function visits(): HasMany
    {
        return $this->hasMany(TreatmentTemplateVisit::class)->orderBy('visit_order');
    }

    public function patientTreatments(): HasMany
    {
        return $this->hasMany(PatientTreatment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
