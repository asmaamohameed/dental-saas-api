<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\InventoryItemController;
use App\Http\Controllers\Api\V1\InventoryTransactionController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoiceItemController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ToothRecordController;
use App\Http\Middleware\EnsureUserRole;
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

        // Read-only endpoints (owner, receptionist, doctor)
        Route::get('services', [ServiceController::class, 'index']);
        Route::get('services/{service}', [ServiceController::class, 'show']);

        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::get('patients/{patient}/invoices-summary', [InvoiceController::class, 'patientSummary']);

        Route::get('invoices/{invoice}/items', [InvoiceItemController::class, 'index']);

        Route::get('invoices/{invoice}/payments', [PaymentController::class, 'index']);
        Route::get('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'show']);

        // Inventory
        Route::apiResource('inventory-items', InventoryItemController::class);
        Route::get('inventory-items/{item}/transactions', [InventoryTransactionController::class, 'index']);
        Route::post('inventory-items/{item}/transactions', [InventoryTransactionController::class, 'store']);

        // Create & Edit endpoints (owner, receptionist)
        Route::middleware(EnsureUserRole::using(UserRole::OWNER, UserRole::RECEPTIONIST))->group(function () {
            Route::post('services', [ServiceController::class, 'store']);
            Route::put('services/{service}', [ServiceController::class, 'update']);
            Route::patch('services/{service}/toggle-active', [ServiceController::class, 'toggleActive']);

            Route::post('invoices', [InvoiceController::class, 'store']);
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update']);

            Route::post('invoices/{invoice}/items', [InvoiceItemController::class, 'store']);
            Route::put('invoices/{invoice}/items/{item}', [InvoiceItemController::class, 'update']);
            Route::delete('invoices/{invoice}/items/{item}', [InvoiceItemController::class, 'destroy']);

            Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store']);
        });

        // Owner-only sensitive actions (delete invoice, delete payment, edit payment, delete service)
        Route::middleware(EnsureUserRole::using(UserRole::OWNER))->group(function () {
            Route::delete('services/{service}', [ServiceController::class, 'destroy']);
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy']);
            Route::put('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'update']);
            Route::delete('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'destroy']);
        });
    });
});
