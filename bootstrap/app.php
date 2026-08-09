<?php

use App\Http\Middleware\EnsureAdminAccess;
use App\Http\Middleware\EnsureTenantAccess;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
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
            'tenant' => EnsureTenantAccess::class,
            'role' => EnsureUserRole::class,
            'locale' => SetLocale::class,
            'admin' => EnsureAdminAccess::class,
        ]);
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
                } elseif ($e instanceof QueryException) {
                    $status = 500;
                    $message = config('app.debug')
                        ? $e->getMessage()
                        : 'Database error.';
                } else {
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
