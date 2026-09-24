<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentTemplateStep extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'treatment_template_id',
        'name_ar',
        'name_en',
        'description',
        'step_order',
        'is_required',
        'is_repeatable',
        'default_duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'is_required' => 'boolean',
            'is_repeatable' => 'boolean',
            'default_duration_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TreatmentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(TreatmentTemplate::class, 'treatment_template_id');
    }

    /**
     * @return HasMany<TreatmentTemplateComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(TreatmentTemplateComponent::class)->with('component');
    }
}
