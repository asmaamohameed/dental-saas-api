<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $plan_type
 * @property string $status
 * @property Carbon $start_date
 * @property Carbon $end_date
 * @property Carbon|null $marked_paid_at
 * @property string|null $notes
 */
class Subscription extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id',
        'plan_type',
        'status',
        'start_date',
        'end_date',
        'marked_paid_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'marked_paid_at' => 'datetime',
            'status' => SubscriptionStatus::class,
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
