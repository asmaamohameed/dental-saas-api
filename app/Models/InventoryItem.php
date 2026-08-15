<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $unit
 * @property string $current_quantity
 * @property string|null $minimum_threshold
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, InventoryTransaction> $transactions
 */
class InventoryItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'unit',
        'current_quantity',
        'minimum_threshold',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'current_quantity' => 'decimal:2',
            'minimum_threshold' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    // Relationships
    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class);
    }

    // Scopes
    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereNotNull('minimum_threshold')
            ->whereColumn('current_quantity', '<=', 'minimum_threshold');
    }
}
