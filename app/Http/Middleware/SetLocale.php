<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * The site language is the Accept-Language header sent by the app.
     * A saved user or clinic language is used only when that header is absent.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $userLocale = $user instanceof Model ? $user->getAttribute('locale') : null;
        $tenantLocale = null;

        if ($user instanceof Model && method_exists($user, 'tenant')) {
            $tenant = $user->getRelationValue('tenant');
            $tenantLocale = is_object($tenant) ? $tenant->getAttribute('locale') : null;
        }

        $locale = $this->supportedLocale($request->header('Accept-Language'))
            ?? $this->supportedLocale($userLocale)
            ?? $this->supportedLocale($tenantLocale)
            ?? (string) config('app.locale');

        App::setLocale($locale);

        return $next($request);
    }

    private function supportedLocale(mixed $locale): ?string
    {
        if (! is_string($locale) || $locale === '') {
            return null;
        }

        $locale = strtolower(substr($locale, 0, 2));

        return in_array($locale, ['en', 'ar'], true) ? $locale : null;
    }
}
