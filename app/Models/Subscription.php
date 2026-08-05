<?php

namespace App\Models;

use App\Enums\SubscriptionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    use BelongsToTenant, HasUuids;

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
}
