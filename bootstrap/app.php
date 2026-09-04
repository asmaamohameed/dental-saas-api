<?php

use App\Exceptions\InvoiceHasPaymentsException;
use App\Exceptions\ServiceProtectedException;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\EnsureUserRole;
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
            'tenant' => EnsureTenantAccess::class,
            'role' => EnsureUserRole::class,
            'locale' => SetLocale::class,
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
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->is('admin/*') || $request->wantsJson()) {
                $status = 500;
                $message = 'An unexpected error occurred.';
                $errors = null;

                if ($e instanceof ValidationException) {
                    $status = 422;
                    $message = $e->getMessage();
                    $errors = $e->errors();
                } elseif ($e instanceof ModelNotFoundException) {
                    $status = 404;
                    $message = 'Resource not found.';
                } elseif ($e instanceof AuthenticationException) {
                    $status = 401;
                    $message = 'Unauthenticated.';
                } elseif ($e instanceof HttpException) {
                    $status = $e->getStatusCode();
                    $message = $e->getMessage() ?: Response::$statusTexts[$status] ?? 'HTTP Error';
                } elseif ($e instanceof InvoiceHasPaymentsException) {
                    $status = 422;
                    $message = $e->getMessage();
                } elseif ($e instanceof ServiceProtectedException) {
                    $status = 422;
                    $message = $e->getMessage();
                } elseif ($e instanceof QueryException) {
                    $message = config('app.debug') ? $e->getMessage() : $message;
                }

                $response = [
                    'status' => 'error',
                    'message' => $message,
                ];

                if (! is_null($errors)) {
                    $response['errors'] = $errors;
                }

                return response()->json($response, $status);
            }
        });
    })->create();
