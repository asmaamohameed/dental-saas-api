<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PatientTreatmentComponent extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'patient_treatment_visit_id',
        'component_id',
        'name',
        'unit_price',
        'quantity',
        'free_quantity',
        'unit',
        'inventory_item_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'quantity' => 'decimal:2',
            'free_quantity' => 'decimal:2',
        ];
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(PatientTreatmentVisit::class, 'patient_treatment_visit_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
