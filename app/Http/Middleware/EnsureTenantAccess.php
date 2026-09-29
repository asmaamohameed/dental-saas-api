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

        if (isset($user->is_active) && $user->is_active === false) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        $user->loadMissing('tenant');

        if (! $user->tenant || $user->tenant->status !== TenantStatus::ACTIVE) {
            return response()->json(['message' => 'Tenant account is inactive or suspended.'], 403);
        }

        if (method_exists($user, 'membershipForClinicId')) {
            $membership = $user->membershipForClinicId((string) $user->tenant_id);

            if ($membership && $membership->is_active === false) {
                return response()->json(['message' => 'Your access to this clinic has been revoked.'], 403);
            }
        }

        $this->currentTenant->set($user->tenant_id);

        return $next($request);
    }
}
