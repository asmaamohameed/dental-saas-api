<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentTemplateVisit extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'treatment_template_id',
        'visit_order',
        'name_ar',
        'name_en',
        'description',
        'estimated_duration_minutes',
    ];

    protected function casts(): array
    {
        return [
            'visit_order' => 'integer',
            'estimated_duration_minutes' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(TreatmentTemplate::class, 'treatment_template_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(TreatmentTemplateComponent::class)->with('component');
    }
}
