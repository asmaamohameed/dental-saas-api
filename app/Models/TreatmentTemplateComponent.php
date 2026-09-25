<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $component_id
 * @property string $quantity
 * @property string $free_quantity
 * @property string $unit_price
 * @property-read Component|null $component
 */
class TreatmentTemplateComponent extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'treatment_template_step_id',
        'component_id',
        'quantity',
        'free_quantity',
        'unit_price',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'free_quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<TreatmentTemplateStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(TreatmentTemplateStep::class, 'treatment_template_step_id');
    }

    /**
     * @return BelongsTo<Component, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
