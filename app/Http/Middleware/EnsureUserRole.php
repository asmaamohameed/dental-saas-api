<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserRole
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $allowedRoles = array_values(array_filter(
            array_map(fn (string $role) => UserRole::tryFrom($role), $roles)
        ));

        if ($user instanceof User) {
            $user->loadMissing('clinicMembers.roles');
        }

        if (! $user instanceof User || ! $user->hasAnyClinicRole($allowedRoles)) {
            return response()->json(['message' => 'Forbidden. Insufficient role permissions.'], 403);
        }

        return $next($request);
    }

    public static function using(UserRole ...$roles): string
    {
        return 'role:'.implode(',', array_map(fn (UserRole $role) => $role->value, $roles));
    }
}
