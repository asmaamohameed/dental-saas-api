<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\InventoryItemController;
use App\Http\Controllers\Api\V1\InventoryTransactionController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoiceItemController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ServiceController;
use App\Http\Controllers\Api\V1\ToothRecordController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Middleware\EnsureUserRole;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // Auth Routes (Public)
    Route::post('auth/login', LoginController::class)->middleware('throttle:login');
    Route::post('auth/forgot-password', ForgotPasswordController::class)->middleware('throttle:6,1');
    Route::post('auth/reset-password', ResetPasswordController::class)->middleware('throttle:6,1');

    // Protected Routes
    Route::middleware(['auth:sanctum', 'tenant'])->group(function () {

        // Audit Logs (Owner only)
        Route::get('audit-logs', [AuditLogController::class, 'index']);

        // Profile & Auth
        Route::prefix('auth')->group(function () {
            Route::post('logout', LogoutController::class);
            Route::get('me', [ProfileController::class, 'show']);
            Route::match(['put', 'patch'], 'me', [ProfileController::class, 'update']);
        });

        // Patients API Resource
        Route::apiResource('patients', PatientController::class);
        Route::get('/patients/search', [PatientController::class, 'search']);

        // Tooth Records
        Route::get('patients/{patient}/tooth-records', [ToothRecordController::class, 'index']);
        Route::post('patients/{patient}/tooth-records', [ToothRecordController::class, 'store']);
        Route::get('patients/{patient}/odontogram', [ToothRecordController::class, 'odontogram']);

        // Appointments API Resource + Custom Endpoint
        Route::apiResource('appointments', AppointmentController::class);
        Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);

        // Read-only endpoints (owner, receptionist, doctor)
        Route::get('doctors', [UserController::class, 'doctors']);
        Route::get('services', [ServiceController::class, 'index']);
        Route::get('services/{service}', [ServiceController::class, 'show']);

        Route::get('invoices', [InvoiceController::class, 'index']);
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
        Route::get('patients/{patient}/invoices-summary', [InvoiceController::class, 'patientSummary']);

        Route::get('invoices/{invoice}/items', [InvoiceItemController::class, 'index']);

        Route::get('invoices/{invoice}/payments', [PaymentController::class, 'index']);
        Route::get('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'show']);

        // Inventory
        Route::apiResource('inventory-items', InventoryItemController::class)->parameters(['inventory-items' => 'inventoryItem']);
        Route::get('inventory-items/{item}/transactions', [InventoryTransactionController::class, 'index']);
        Route::post('inventory-items/{item}/transactions', [InventoryTransactionController::class, 'store']);
        Route::patch('inventory-items/{inventoryItem}/restore', [InventoryItemController::class, 'restore']);
        Route::patch('inventory-items/{inventoryItem}', [InventoryItemController::class, 'update']);

        // Dashboard Analytics
        Route::get('dashboard/financial-analytics', [DashboardController::class, 'financialAnalytics']);
        Route::get('dashboard/income-expense', [DashboardController::class, 'incomeExpense']);
        Route::get('dashboard/patients', [DashboardController::class, 'patients']);
        Route::get('dashboard/cashflow', [DashboardController::class, 'cashflow']);
        Route::get('dashboard/expense-breakdown', [DashboardController::class, 'expenseBreakdown']);
        Route::get('dashboard/invoice-status', [DashboardController::class, 'invoiceStatus']);

        // Expenses
        Route::get('expenses', [ExpenseController::class, 'index']);

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
            Route::post('expenses', [ExpenseController::class, 'store']);
        });

        // Owner-only sensitive actions (delete invoice, delete payment, edit payment, delete service)
        Route::middleware(EnsureUserRole::using(UserRole::OWNER))->group(function () {
            Route::get('staff', [UserController::class, 'index']);
            Route::post('staff', [UserController::class, 'store']);
            Route::put('staff/{user}', [UserController::class, 'update']);
            Route::patch('staff/{user}/toggle-active', [UserController::class, 'toggleActive']);
            Route::delete('services/{service}', [ServiceController::class, 'destroy']);
            Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy']);
            Route::put('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'update']);
            Route::delete('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'destroy']);
            Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);
        });
    });
});
