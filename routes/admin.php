<?php

use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\TenantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', LoginController::class);
});

Route::middleware(['auth:sanctum', 'is_admin'])->group(function () {
    Route::get('/ping', function (Request $request) {
        return response()->json([
            'message' => 'Admin API works!',
            'admin' => $request->user(),
        ]);
    });

    // Super Admin Tenant Management Endpoints
    Route::apiResource('tenants', TenantController::class)->except('destroy');
    Route::apiResource('subscriptions', SubscriptionController::class)->only(['index', 'store', 'update']);
    Route::patch('subscriptions/{subscription}/mark-paid', [SubscriptionController::class, 'markAsPaid']);
});
