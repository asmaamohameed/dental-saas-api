<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() instanceof AdminUser) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
