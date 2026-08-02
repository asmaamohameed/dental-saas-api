<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantAccess
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {

        $user = $request->user();

        if (! $user || ! $user->tenant_id) {
            return response()->json(['message' => 'Unauthorized or missing tenant context.'], 403);
        }

        /** @var Tenant|null $tenant */
        $tenant = $user->tenant;

        if (! $tenant || $tenant->status !== 'active') {
            return response()->json(['message' => 'Tenant account is inactive or suspended.'], 403);
        }

        return $next($request);
    }
}
