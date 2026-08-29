<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;

class TenantAwareUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope('tenant');
    }
}
