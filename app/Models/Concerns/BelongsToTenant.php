<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-require-extends Model
 *
 * @property string|int|null $tenant_id
 */
trait BelongsToTenant
{
    /**
     * Boot the trait and apply the global scope.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            /** @var User|null $user */
            $user = auth()->user();

            if ($user && $user->tenant_id) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $user->tenant_id);
            }
        });

        static::creating(function (Model $model) {
            /** @var User|null $user */
            $user = auth()->user();

            if ($user && $user->tenant_id) {
                $model->setAttribute('tenant_id', $user->tenant_id);
            }
        });
    }

    /**
     * Get the tenant that owns the model.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
