<?php

use App\Billing\Domain\Exceptions\BillingDomainException;
use App\Exceptions\InvoiceHasPaymentsException;
use App\Http\Middleware\ClearPlatformTenantContext;
use App\Http\Middleware\EnsureClinicPermission;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\MeasureRequestTime;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'is_admin' => EnsureUserIsAdmin::class,
            'platform.context' => ClearPlatformTenantContext::class,
            'tenant' => EnsureTenantAccess::class,
            'role' => EnsureUserRole::class,
            'clinic.permission' => EnsureClinicPermission::class,
            'locale' => SetLocale::class,
        ]);

        $middleware->api(append: [
            MeasureRequestTime::class,
            SetLocale::class,
        ]);

        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            ValidateCsrfToken::class,
            EnsureFrontendRequestsAreStateful::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesRequests::class,
            EnsureTenantAccess::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

        $middleware->appendToPriorityList(
            after: Authenticate::class,
            append: EnsureTenantAccess::class,
        );

        $middleware->appendToPriorityList(
            after: Authenticate::class,
            append: SetLocale::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->is('admin/*') || $request->is('broadcasting/auth') || $request->wantsJson()) {
                $headerLocale = strtolower(substr((string) $request->header('Accept-Language', ''), 0, 2));
                if (in_array($headerLocale, ['en', 'ar'], true)) {
                    app()->setLocale($headerLocale);
                }

                $status = 500;
                $message = __('errors.unexpected');
                $errors = null;
                $headers = method_exists($e, 'getHeaders') ? $e->getHeaders() : [];

                if ($e instanceof ValidationException) {
                    $status = 422;
                    $message = $e->getMessage();
                    $errors = $e->errors();
                } elseif ($e instanceof AuthenticationException) {
                    $status = 401;
                    $message = __('errors.unauthenticated');
                } elseif ($e instanceof HttpException) {
                    $status = $e->getStatusCode();
                    $message = $e->getMessage() ?: Response::$statusTexts[$status] ?? 'HTTP Error';

                    if ($status === 404 && $e->getPrevious() instanceof ModelNotFoundException) {
                        $message = __('errors.not_found');
                    }
                } elseif ($e instanceof InvoiceHasPaymentsException) {
                    $status = 422;
                    $message = $e->getMessage();
                } elseif ($e instanceof BillingDomainException) {
                    $status = 422;
                    $message = $e->getMessage();
                } elseif ($e instanceof QueryException) {
                    $sqlState = $e->errorInfo[0] ?? null;

                    if ($sqlState === '23P01') {
                        $status = 422;
                        $message = __('errors.invalid');
                        $errors = [
                            'doctor_id' => [__('errors.doctor_slot_taken')],
                        ];
                    } else {
                        $message = config('app.debug') ? $e->getMessage() : $message;
                    }
                }

                $response = [
                    'status' => 'error',
                    'message' => $message,
                ];

                if (! is_null($errors)) {
                    $response['errors'] = $errors;
                }

                return response()->json($response, $status, $headers);
            }
        });

        $exceptions->respond(function ($response, Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->is('admin/*')) {
                return app(MeasureRequestTime::class)->applyTiming($request, $response);
            }

            return $response;
        });
    })->create();
