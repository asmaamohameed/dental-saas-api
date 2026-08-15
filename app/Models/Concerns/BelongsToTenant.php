<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @phpstan-require-extends Model
 *
 * @property string|int|null $tenant_id
 *
 * @method static void creating(\Closure $callback)
 * @method static void addGlobalScope(string $identifier, \Closure $scope)
 */
trait BelongsToTenant
{
    /**
     * Boot the trait and apply the global scope.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = app(CurrentTenant::class)->id();

            if (! $tenantId) {
                throw new \RuntimeException(
                    'Attempted to query '.$builder->getModel()::class.' without a tenant context.'
                );
            }
            $builder->where($builder->getModel()->getTable().'.tenant_id', $tenantId);
        });

        static::creating(function (Model $model) {
            $tenantId = app(CurrentTenant::class)->id();

            if (! $tenantId) {
                throw new \RuntimeException(
                    'Attempted to create '.$model::class.' without a tenant context.'
                );
            }
            $model->setAttribute('tenant_id', $tenantId);
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
