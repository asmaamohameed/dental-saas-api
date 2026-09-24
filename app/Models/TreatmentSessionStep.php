<?php

namespace App\Models;

use App\Enums\SessionStepStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentSessionStep extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'treatment_session_id',
        'treatment_template_step_id',
        'custom_step_name',
        'custom_step_description',
        'occurrence_number',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'occurrence_number' => 'integer',
            'status' => SessionStepStatus::class,
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TreatmentSession::class, 'treatment_session_id');
    }

    public function templateStep(): BelongsTo
    {
        return $this->belongsTo(TreatmentTemplateStep::class, 'treatment_template_step_id');
    }

    public function displayName(): string
    {
        if ($this->custom_step_name) {
            return $this->custom_step_name;
        }

        $templateStep = $this->relationLoaded('templateStep') ? $this->templateStep : null;

        return $templateStep?->name_en ?: $templateStep?->name_ar ?: 'Step';
    }
}
