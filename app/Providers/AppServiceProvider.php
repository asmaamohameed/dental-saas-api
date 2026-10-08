<?php

namespace App\Providers;

use App\Auth\TenantAwareUserProvider;
use App\Models\PersonalAccessToken;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentTenant::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        Auth::provider('tenant_aware_eloquent', function ($app, array $config) {
            return new TenantAwareUserProvider($app['hash'], $config['model']);
        });

        RateLimiter::for('login', function (Request $request) {
            $key = Str::lower($request->input('email')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('admin-mutations', function (Request $request) {
            $actor = (string) ($request->user()?->getAuthIdentifier() ?: $request->ip());

            return Limit::perMinute(20)->by('admin-mutations|'.$actor);
        });

        if (config('performance.request_timing_enabled')) {
            DB::whenQueryingForLongerThan(
                (int) config('performance.slow_query_threshold_ms', 500),
                function ($connection, $event): void {
                    if (! config('performance.request_timing_enabled')) {
                        return;
                    }

                    Log::warning('Slow query', [
                        'sql' => $event->sql,
                        'duration_ms' => $event->time,
                    ]);
                }
            );
        }
    }
}
