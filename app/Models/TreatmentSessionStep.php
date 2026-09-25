<?php

namespace App\Models;

use App\Enums\SessionStepStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $treatment_session_id
 * @property string|null $treatment_template_step_id
 * @property string|null $custom_step_name
 * @property string|null $custom_step_description
 * @property int $occurrence_number
 * @property SessionStepStatus $status
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TreatmentSession $session
 * @property-read TreatmentTemplateStep|null $templateStep
 */
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

    /**
     * @return BelongsTo<TreatmentSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TreatmentSession::class, 'treatment_session_id');
    }

    /**
     * @return BelongsTo<TreatmentTemplateStep, $this>
     */
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
