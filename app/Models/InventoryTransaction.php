<?php

namespace App\Models;

use App\Enums\InventoryTransactionType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $inventory_item_id
 * @property string $type
 * @property string $quantity
 * @property string|null $reason
 * @property string|null $performed_by
 * @property Carbon|null $created_at
 * @property-read InventoryItem $inventoryItem
 * @property-read User|null $performer
 */
class InventoryTransaction extends Model
{
    use BelongsToTenant, HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'inventory_item_id',
        'type',
        'quantity',
        'reason',
        'performed_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'type' => InventoryTransactionType::class,
        ];
    }

    // Relationships
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
