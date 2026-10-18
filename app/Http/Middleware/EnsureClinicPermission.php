<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Roles\ClinicPermissionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureClinicPermission
{
    public function __construct(private readonly ClinicPermissionGate $gate) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $user->loadMissing('clinicMembers.roles');
        }

        $roleAllows = $user instanceof User && $this->gate->baselineAllows($user, $permission);

        if (! $user instanceof User || ! $user->clinicCan($permission, $roleAllows)) {
            return response()->json(['message' => 'Forbidden. Insufficient role permissions.'], 403);
        }

        return $next($request);
    }
}
