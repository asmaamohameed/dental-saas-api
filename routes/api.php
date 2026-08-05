<?php

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\PatientController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('auth/login', LoginController::class);

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('logout', LogoutController::class);
            Route::get('me', [ProfileController::class, 'show']);
        });

        Route::apiResource('patients', PatientController::class);
    });
});
