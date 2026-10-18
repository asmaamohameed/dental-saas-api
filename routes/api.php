<?php

use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\ProfileAvatarController;
use App\Http\Controllers\Api\V1\Auth\ProfileController;
use App\Http\Controllers\Api\V1\Auth\ResetPasswordController;
use App\Http\Controllers\Api\V1\ClinicBrandingController;
use App\Http\Controllers\Api\V1\ClinicRolePermissionController;
use App\Http\Controllers\Api\V1\ClinicWorkingHoursController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\InventoryItemController;
use App\Http\Controllers\Api\V1\InventoryTransactionController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoiceItemController;
use App\Http\Controllers\Api\V1\PatientController;
use App\Http\Controllers\Api\V1\PatientTreatmentController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\RefundController;
use App\Http\Controllers\Api\V1\ToothRecordController;
use App\Http\Controllers\Api\V1\TreatmentPlanController;
use App\Http\Controllers\Api\V1\TreatmentTemplateController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\XrayAttachmentController;
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

        Route::get('clinic/working-hours', [ClinicWorkingHoursController::class, 'show']);

        // Profile & Auth
        Route::prefix('auth')->group(function () {
            Route::post('logout', LogoutController::class);
            Route::get('me', [ProfileController::class, 'show']);
            Route::match(['put', 'patch'], 'me', [ProfileController::class, 'update']);
            Route::post('me/avatar', [ProfileAvatarController::class, 'store']);
            Route::delete('me/avatar', [ProfileAvatarController::class, 'destroy']);
        });

        // Patients API Resource
        Route::apiResource('patients', PatientController::class);

        // Tooth Records
        Route::get('patients/{patient}/tooth-records', [ToothRecordController::class, 'index']);
        Route::post('patients/{patient}/tooth-records', [ToothRecordController::class, 'store']);
        Route::get('patients/{patient}/odontogram', [ToothRecordController::class, 'odontogram']);
        Route::get('patients/{patient}/treatments', [PatientTreatmentController::class, 'forPatient']);
        Route::get('patients/{patient}/next-treatment', [PatientTreatmentController::class, 'next']);
        Route::get('patients/{patient}/treatment-plans', [TreatmentPlanController::class, 'index'])->middleware('clinic.permission:patient:view');
        Route::get('patients/{patient}/xray-attachments', [XrayAttachmentController::class, 'index']);
        Route::post('patients/{patient}/xray-attachments', [XrayAttachmentController::class, 'store']);
        Route::delete('patients/{patient}/xray-attachments/{xray}', [XrayAttachmentController::class, 'destroy']);

        // Appointments API Resource + Custom Endpoint
        Route::get('appointments/unresolved', [AppointmentController::class, 'unresolved']);
        Route::apiResource('appointments', AppointmentController::class);
        Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);

        // Read-only endpoints (owner, receptionist, doctor)
        Route::get('doctors/availability', [UserController::class, 'doctorAvailability']);
        Route::get('doctors', [UserController::class, 'doctors']);
        Route::apiResource('treatment-templates', TreatmentTemplateController::class)
            ->parameters(['treatment-templates' => 'treatmentTemplate'])
            ->only(['index', 'show'])
            ->middleware('clinic.permission:treatment:view');
        Route::apiResource('patient-treatments', PatientTreatmentController::class)
            ->parameters(['patient-treatments' => 'patientTreatment'])
            ->only(['index', 'show'])
            ->middleware('clinic.permission:patient:view');
        Route::get('patient-treatments/{patientTreatment}/invoice-summary', [PatientTreatmentController::class, 'invoiceSummary']);

        Route::middleware(EnsureUserRole::using(UserRole::OWNER, UserRole::DOCTOR))->group(function () {
            Route::patch('patient-treatments/{patientTreatment}/status', [PatientTreatmentController::class, 'updateStatus']);
            Route::post('patient-treatments/{patientTreatment}/retreat', [PatientTreatmentController::class, 'retreat']);
            Route::delete('patient-treatments/{patientTreatment}', [PatientTreatmentController::class, 'destroy']);
        });

        Route::middleware(EnsureUserRole::using(UserRole::OWNER, UserRole::DOCTOR, UserRole::ASSISTANT))->group(function () {
            Route::post('patient-treatments/{patientTreatment}/sessions', [PatientTreatmentController::class, 'storeSession']);
            Route::patch('treatment-sessions/{treatmentSession}', [PatientTreatmentController::class, 'updateSession']);
            Route::patch('patient-treatments/{patientTreatment}/teeth', [PatientTreatmentController::class, 'updateTeeth']);
        });

        Route::get('invoices', [InvoiceController::class, 'index'])->middleware('clinic.permission:invoice:view');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('clinic.permission:invoice:view');
        Route::get('patients/{patient}/invoices-summary', [InvoiceController::class, 'patientSummary'])->middleware('clinic.permission:invoice:view');

        Route::get('invoices/{invoice}/items', [InvoiceItemController::class, 'index']);

        Route::get('invoices/{invoice}/payments', [PaymentController::class, 'index']);
        Route::get('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'show']);

        // Inventory
        Route::apiResource('inventory-items', InventoryItemController::class)->parameters(['inventory-items' => 'inventoryItem']);
        Route::get('inventory-items/{inventoryItem}/transactions', [InventoryTransactionController::class, 'index']);
        Route::post('inventory-items/{inventoryItem}/transactions', [InventoryTransactionController::class, 'store']);
        Route::patch('inventory-items/{inventoryItem}/restore', [InventoryItemController::class, 'restore']);

        // Dashboard Analytics
        Route::middleware('clinic.permission:dashboard:financials')->group(function () {
            Route::get('dashboard/financial-analytics', [DashboardController::class, 'financialAnalytics']);
            Route::get('dashboard/income-expense', [DashboardController::class, 'incomeExpense']);
            Route::get('dashboard/patients', [DashboardController::class, 'patients']);
            Route::get('dashboard/cashflow', [DashboardController::class, 'cashflow']);
            Route::get('dashboard/expense-breakdown', [DashboardController::class, 'expenseBreakdown']);
            Route::get('dashboard/invoice-status', [DashboardController::class, 'invoiceStatus']);
            Route::get('dashboard/refund-breakdown', [DashboardController::class, 'refundBreakdown']);
        });

        Route::get('expenses', [ExpenseController::class, 'index'])->middleware('clinic.permission:expense:view');
        Route::post('expenses', [ExpenseController::class, 'store'])->middleware('clinic.permission:expense:create');

        // Treatments and invoices: owner, doctor, assistant, receptionist.
        Route::middleware(EnsureUserRole::using(UserRole::OWNER, UserRole::DOCTOR, UserRole::ASSISTANT, UserRole::RECEPTIONIST))->group(function () {
            Route::post('treatment-templates', [TreatmentTemplateController::class, 'store'])->middleware('clinic.permission:treatment:create');
            Route::put('treatment-templates/{treatmentTemplate}', [TreatmentTemplateController::class, 'update'])->middleware('clinic.permission:treatment:edit');
            Route::patch('treatment-templates/{treatmentTemplate}/toggle-active', [TreatmentTemplateController::class, 'toggleActive'])->middleware('clinic.permission:treatment:toggleActive');
            Route::post('patients/{patient}/treatment-plans', [TreatmentPlanController::class, 'store']);
            Route::put('treatment-plans/{treatmentPlan}', [TreatmentPlanController::class, 'update']);
            Route::post('patient-treatments', [PatientTreatmentController::class, 'store']);
            Route::post('patients/{patient}/treatments', [PatientTreatmentController::class, 'storeForPatient']);
            Route::put('patient-treatments/{patientTreatment}', [PatientTreatmentController::class, 'update']);

            Route::post('invoices', [InvoiceController::class, 'store'])->middleware('clinic.permission:invoice:create');
            Route::post('invoices/from-treatment/{patientTreatment}', [InvoiceController::class, 'storeFromTreatment'])->middleware('clinic.permission:invoice:create');
            Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('clinic.permission:invoice:edit');

            Route::post('invoices/{invoice}/items', [InvoiceItemController::class, 'store']);
            Route::put('invoices/{invoice}/items/{item}', [InvoiceItemController::class, 'update']);
            Route::delete('invoices/{invoice}/items/{item}', [InvoiceItemController::class, 'destroy']);

            Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->middleware('clinic.permission:payment:create');
        });

        Route::middleware('clinic.permission:invoice:refund')->group(function () {
            Route::get('refunds', [RefundController::class, 'index']);
            Route::get('refunds/{refund}', [RefundController::class, 'show']);
            Route::post('invoices/{invoice}/refunds', [RefundController::class, 'store']);
        });

        Route::middleware('clinic.permission:clinic:branding')->group(function () {
            Route::post('clinic/logo', [ClinicBrandingController::class, 'updateLogo']);
            Route::patch('clinic/theme', [ClinicBrandingController::class, 'updateTheme']);
        });

        Route::get('staff', [UserController::class, 'index'])->middleware('clinic.permission:staff:view');
        Route::post('staff', [UserController::class, 'store'])->middleware('clinic.permission:staff:create');
        Route::put('staff/{user}', [UserController::class, 'update'])->middleware('clinic.permission:staff:edit');
        Route::patch('staff/{user}/toggle-active', [UserController::class, 'toggleActive'])->middleware('clinic.permission:staff:toggleActive');
        Route::delete('treatment-templates/{treatmentTemplate}', [TreatmentTemplateController::class, 'destroy'])->middleware('clinic.permission:treatment:delete');
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->middleware('clinic.permission:invoice:delete');
        Route::put('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'update'])->middleware('clinic.permission:payment:edit');
        Route::delete('invoices/{invoice}/payments/{payment}', [PaymentController::class, 'destroy'])->middleware('clinic.permission:payment:delete');

        Route::middleware(EnsureUserRole::using(UserRole::OWNER))->group(function () {
            Route::get('role-permissions', [ClinicRolePermissionController::class, 'index']);
            Route::put('role-permissions/{role}', [ClinicRolePermissionController::class, 'update']);
            Route::put('clinic/working-hours', [ClinicWorkingHoursController::class, 'update']);
            Route::delete('treatment-plans/{treatmentPlan}', [TreatmentPlanController::class, 'destroy']);
            Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy']);
        });
    });
});
