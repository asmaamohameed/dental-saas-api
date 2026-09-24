<?php

namespace App\Models;

use App\Enums\TreatmentCategory;
use App\Enums\TreatmentVisitType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'version',
        'is_current',
        'root_template_id',
        'previous_version_id',
    ];

    protected function casts(): array
    {
        return [
            'category' => TreatmentCategory::class,
            'default_price' => 'decimal:2',
            'estimated_duration_minutes' => 'integer',
            'visit_type' => TreatmentVisitType::class,
            'is_active' => 'boolean',
            'version' => 'integer',
            'is_current' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (TreatmentTemplate $template) {
            if (! $template->root_template_id) {
                $template->forceFill(['root_template_id' => $template->id])->saveQuietly();
            }
        });
    }

    public function steps(): HasMany
    {
        return $this->hasMany(TreatmentTemplateStep::class)->orderBy('step_order');
    }

    public function patientTreatments(): HasMany
    {
        return $this->hasMany(PatientTreatment::class);
    }

    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_template_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'root_template_id', 'root_template_id')->orderByDesc('version');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->where('is_current', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function familyHasPatientTreatments(): bool
    {
        $rootId = $this->root_template_id ?: $this->id;

        return PatientTreatment::query()
            ->whereIn('treatment_template_id', self::query()->where('root_template_id', $rootId)->select('id'))
            ->exists();
    }
}
