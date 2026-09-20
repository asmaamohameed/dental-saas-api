<?php

namespace App\Models;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $category
 * @property string $title
 * @property string $amount
 * @property \Illuminate\Support\Carbon $expense_date
 * @property string|null $created_by
 * @property string|null $notes
 * @property-read User|null $creator
 */
class Expense extends Model
{
    use Auditable, BelongsToTenant, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'category',
        'title',
        'amount',
        'expense_date',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expense_date' => 'date',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
