<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read bool $is_low_stock
 * @property float|string|null $default_price
 */
class Component extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'name_ar',
        'name_en',
        'default_price',
        'unit',
        'current_quantity',
        'minimum_threshold',
        'inventory_item_id',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_price' => 'decimal:2',
            'current_quantity' => 'decimal:2',
            'minimum_threshold' => 'decimal:2',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected function isLowStock(): Attribute
    {
        return Attribute::get(function (): bool {
            if ($this->minimum_threshold === null) {
                return false;
            }

            return bccomp((string) $this->current_quantity, (string) $this->minimum_threshold, 2) <= 0;
        });
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function templateComponents(): HasMany
    {
        return $this->hasMany(TreatmentTemplateComponent::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(ComponentStockMovement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereNotNull('minimum_threshold')
            ->whereColumn('current_quantity', '<=', 'minimum_threshold');
    }
}
