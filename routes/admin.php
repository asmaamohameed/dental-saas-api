<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Auth\LoginController;
use App\Http\Controllers\Admin\Auth\LogoutController;
use App\Http\Controllers\Admin\Auth\MeController;
use App\Http\Controllers\Admin\ClinicController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\MembershipController;
use App\Http\Controllers\Admin\PlatformUserController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SubscriptionController;
use App\Http\Controllers\Admin\TenantController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', LoginController::class)->middleware('throttle:login');
});

Route::middleware(['auth:sanctum', 'is_admin', 'platform.context'])->group(function () {
    Route::post('auth/logout', LogoutController::class);
    Route::get('auth/me', MeController::class);

    Route::get('/ping', function (Request $request) {
        $admin = $request->user();

        return response()->json([
            'message' => 'Admin API works!',
            'admin' => [
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ],
        ]);
    });

    Route::get('dashboard', DashboardController::class);

    Route::get('clinics', [ClinicController::class, 'index']);
    Route::post('clinics', [ClinicController::class, 'store'])->middleware('throttle:admin-mutations');
    Route::get('clinics/{clinic}', [ClinicController::class, 'show'])->whereUuid('clinic');
    Route::post('clinics/{clinic}/members', [ClinicController::class, 'addMember'])->whereUuid('clinic')->middleware('throttle:admin-mutations');
    Route::patch('clinics/{clinic}', [ClinicController::class, 'update'])->whereUuid('clinic')->middleware('throttle:admin-mutations');
    Route::post('clinics/{clinic}/suspend', [ClinicController::class, 'suspend'])->whereUuid('clinic')->middleware('throttle:admin-mutations');
    Route::post('clinics/{clinic}/activate', [ClinicController::class, 'activate'])->whereUuid('clinic')->middleware('throttle:admin-mutations');
    Route::get('clinics/{clinic}/members', [ClinicController::class, 'members'])->whereUuid('clinic');

    Route::get('users', [PlatformUserController::class, 'index']);
    Route::get('users/{platformUser}', [PlatformUserController::class, 'show'])->whereUuid('platformUser');
    Route::post('users/{platformUser}/activate', [PlatformUserController::class, 'activate'])->whereUuid('platformUser')->middleware('throttle:admin-mutations');
    Route::post('users/{platformUser}/deactivate', [PlatformUserController::class, 'deactivate'])->whereUuid('platformUser')->middleware('throttle:admin-mutations');
    Route::post('users/{platformUser}/revoke-sessions', [PlatformUserController::class, 'revokeSessions'])->whereUuid('platformUser')->middleware('throttle:admin-mutations');

    Route::get('memberships', [MembershipController::class, 'index']);
    Route::post('memberships', [MembershipController::class, 'store'])->middleware('throttle:admin-mutations');
    Route::patch('memberships/{membership}', [MembershipController::class, 'update'])->whereUuid('membership')->middleware('throttle:admin-mutations');
    Route::post('memberships/{membership}/revoke', [MembershipController::class, 'revoke'])->whereUuid('membership')->middleware('throttle:admin-mutations');
    Route::post('memberships/{membership}/restore', [MembershipController::class, 'restore'])->whereUuid('membership')->middleware('throttle:admin-mutations');

    Route::get('roles', [RoleController::class, 'index']);
    Route::get('roles/{role}', [RoleController::class, 'show'])->where('role', 'owner|doctor|assistant|receptionist');

    Route::get('audit-logs', [AuditLogController::class, 'index']);

    Route::middleware('throttle:admin-mutations')->group(function () {
        Route::apiResource('tenants', TenantController::class)->except(['index', 'show', 'destroy']);
        Route::apiResource('subscriptions', SubscriptionController::class)->only(['store', 'update']);
        Route::patch('subscriptions/{subscription}/mark-paid', [SubscriptionController::class, 'markAsPaid']);
    });

    Route::apiResource('tenants', TenantController::class)->only(['index', 'show']);
    Route::apiResource('subscriptions', SubscriptionController::class)->only(['index']);
});
