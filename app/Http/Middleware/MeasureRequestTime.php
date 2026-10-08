<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class MeasureRequestTime
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled()) {
            return $next($request);
        }

        $start = defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $response = $next($request);

        return $this->applyTiming($request, $response, $start);
    }

    public function applyTiming(Request $request, Response $response, ?float $start = null): Response
    {
        if (! $this->enabled()) {
            return $response;
        }

        $start ??= defined('LARAVEL_START') ? LARAVEL_START : microtime(true);
        $durationMs = round((microtime(true) - $start) * 1000, 1);

        $response->headers->set('Server-Timing', 'app;dur='.number_format($durationMs, 1, '.', ''));

        if ($durationMs > (float) config('performance.slow_request_threshold_ms', 500)) {
            Log::warning('Slow request', [
                'method' => $request->method(),
                'route' => $request->route()?->uri() ?? 'unmatched',
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
            ]);
        }

        return $response;
    }

    private function enabled(): bool
    {
        return (bool) config('performance.request_timing_enabled', true);
    }
}
