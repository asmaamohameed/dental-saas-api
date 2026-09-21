<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TreatmentTemplateComponent extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'treatment_template_visit_id',
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

    public function visit(): BelongsTo
    {
        return $this->belongsTo(TreatmentTemplateVisit::class, 'treatment_template_visit_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
