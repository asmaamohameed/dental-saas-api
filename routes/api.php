<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\ToothRecordController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    Route::post('auth/login', LoginController::class);

    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {
        Route::prefix('auth')->group(function () {
            Route::post('logout', LogoutController::class);
            Route::get('me', [ProfileController::class, 'show']);
        });

        Route::apiResource('patients', PatientController::class)->except(['index', 'store'])
            ->middleware(['can:view,patient']);

        Route::get('patients', [PatientController::class, 'index'])
            ->middleware('can:viewAny,App\Models\Patient')->name('patients.index');
        Route::post('patients', [PatientController::class, 'store'])
            ->middleware('can:create,App\Models\Patient')->name('patients.store');
        Route::get('patients/{patient}', [PatientController::class, 'show'])
            ->middleware('can:view,patient')->name('patients.show');
        Route::put('patients/{patient}', [PatientController::class, 'update'])
            ->middleware('can:update,patient')->name('patients.update');
        Route::delete('patients/{patient}', [PatientController::class, 'destroy'])
            ->middleware(['can:delete,patient', 'role:'.UserRole::OWNER->value])->name('patients.destroy');

        Route::get('patients/{patient}/tooth-records', [ToothRecordController::class, 'index'])
            ->middleware('can:viewAny,patient,App\Models\ToothRecord');
        Route::post('patients/{patient}/tooth-records', [ToothRecordController::class, 'store'])
            ->middleware('can:create,patient,App\Models\ToothRecord');
        Route::get('patients/{patient}/odontogram', [ToothRecordController::class, 'odontogram'])
            ->middleware('can:viewAny,patient,App\Models\ToothRecord');

        Route::prefix('appointments')->group(function () {
            Route::get('/', [AppointmentController::class, 'index'])
                ->middleware('can:viewAny,App\Models\Appointment');

            Route::post('/', [AppointmentController::class, 'store'])
                ->middleware('can:create,App\Models\Appointment');

            Route::get('/{appointment}', [AppointmentController::class, 'show'])
                ->middleware('can:view,appointment');

            Route::put('/{appointment}', [AppointmentController::class, 'update'])
                ->middleware('can:update,appointment');

            Route::delete('/{appointment}', [AppointmentController::class, 'destroy'])
                ->middleware('can:delete,appointment');

            Route::patch('/{appointment}/status', [AppointmentController::class, 'updateStatus'])
                ->middleware('can:updateStatus,appointment');
        });
    });
});
