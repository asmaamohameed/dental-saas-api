<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Support\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    public function __construct(protected CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {

        $user = $request->user();

        if (! $user || ! $user->tenant_id) {
            return response()->json(['message' => 'Unauthorized or missing tenant context.'], 403);
        }

        $user->loadMissing('tenant');

        if (! $user->tenant || $user->tenant->status !== TenantStatus::ACTIVE) {
            return response()->json(['message' => 'Tenant account is inactive or suspended.'], 403);
        }

        $this->currentTenant->set($user->tenant_id);

        return $next($request);
    }
}
