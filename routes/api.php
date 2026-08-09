<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\ToothRecordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth Routes (Public)
    Route::post('auth/login', LoginController::class);

    // Protected Routes
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

        // Profile & Auth
        Route::prefix('auth')->group(function () {
            Route::post('logout', LogoutController::class);
            Route::get('me', [ProfileController::class, 'show']);
        });

        // Patients API Resource
        Route::apiResource('patients', PatientController::class);

        // Tooth Records
        Route::get('patients/{patient}/tooth-records', [ToothRecordController::class, 'index']);
        Route::post('patients/{patient}/tooth-records', [ToothRecordController::class, 'store']);
        Route::get('patients/{patient}/odontogram', [ToothRecordController::class, 'odontogram']);

        // Appointments API Resource + Custom Endpoint
        Route::apiResource('appointments', AppointmentController::class);
        Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);

    });
});
