<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Default to checking the Accept-Language header first
        $locale = $request->header('Accept-Language');
        
        // Override with user's specific locale preference if authenticated
        if ($request->user() && $request->user()->locale) {
            $locale = $request->user()->locale;
        } 
        // Or fallback to the tenant's default locale
        elseif ($request->user() && $request->user()->tenant_id) {
            // Avoid N+1 by checking if tenant relation is loaded, though it's typically fine for a single lookup per request.
            $tenantLocale = $request->user()->tenant->locale ?? null;
            if ($tenantLocale) {
                $locale = $tenantLocale;
            }
        }

        // Clean up locale string (e.g., 'en-US,en;q=0.9' -> 'en')
        if ($locale) {
            $locale = substr($locale, 0, 2);
        }

        // Apply locale if supported
        if ($locale && in_array($locale, ['en', 'ar'])) {
            App::setLocale($locale);
        } else {
            App::setLocale(config('app.locale'));
        }

        return $next($request);
    }
}
