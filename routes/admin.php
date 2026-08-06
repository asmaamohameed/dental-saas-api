<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\TenantUserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', LoginController::class);
});

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::get('/ping', function (Request $request) {
        return response()->json([
            'message' => 'Admin API works!',
            'admin' => $request->user(),
        ]);
    });

    // Super Admin Tenant Management Endpoints
    Route::apiResource('tenants', TenantController::class);
    Route::get('tenants/{tenant}/users', [TenantUserController::class, 'index']);
    Route::post('tenants/{tenant}/users', [TenantUserController::class, 'store']);
});
