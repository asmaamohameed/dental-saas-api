<?php

namespace App\Models;

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
 * @property string|null $component_id
 * @property string $name
 * @property string $unit_price
 * @property string $quantity
 * @property string $free_quantity
 * @property string $unit
 * @property string|null $inventory_item_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TreatmentSession $session
 * @property-read Component|null $component
 * @property-read InventoryItem|null $inventoryItem
 */
class PatientTreatmentComponent extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'treatment_session_id',
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

    /**
     * @return BelongsTo<TreatmentSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TreatmentSession::class, 'treatment_session_id');
    }

    /**
     * @return BelongsTo<Component, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
