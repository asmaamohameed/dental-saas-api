# Module 1 Execution Plan — Developer 1

**Scope:** Patients + Appointments + Tooth Records (Odontogram) + Admin Panel (tenants, subscriptions)

**General principle:** Migrations, Models, and Middleware are already done (Phases 1-4 in implementation_plan). What's left is only the layer on top: **Request Validation → Controller → API Resource → Routes**, one module fully completed before moving to the next, to minimize conflicts with Developer 2's work.

No extra Service classes or Repository pattern beyond what's needed — Controllers stay simple (direct CRUD), and a Service layer (like AuthService) is only used when there's actual logic worth isolating (e.g. the historical tooth_records logic or invoice status derivation).

**Suggested execution order:**
1. Patients (foundation everything else depends on)
2. Admin Panel — Tenants + Subscriptions (fully independent, can be done in parallel)
3. Appointments (depends on Patients)
4. Tooth Records / Odontogram (depends on Patients + Appointments)

---

## 1. Patients Module

### 1.1 Request Validation
- `app/Http/Requests/V1/Patient/StorePatientRequest.php`
  - `full_name` required|string|max:255
  - `phone` required|string
  - `date_of_birth` nullable|date
  - `gender` nullable|in:male,female
  - `medical_history` nullable|array
  - `notes` nullable|string
- `app/Http/Requests/V1/Patient/UpdatePatientRequest.php` (same rules but `sometimes`)

### 1.2 Controller
`app/Http/Controllers/Api/V1/PatientController.php`
- `index()` — list + pagination + search by name/phone (`?search=`)
- `store()`
- `show()`
- `update()`
- `destroy()` — no soft-delete column in the schema, so make hard delete available only to role=owner (use the existing EnsureUserRole middleware)

All actions rely on the existing `BelongsToTenant` global scope — no need for manual tenant_id filtering anywhere.

### 1.3 Resource
`app/Http/Resources/V1/PatientResource.php`

### 1.4 Routes (routes/api.php)
```
Route::apiResource('patients', PatientController::class);
```
Under the existing middleware group (`auth:sanctum`, `tenant.access`).

---

## 2. Admin Panel — Tenants + Subscriptions

### 2.1 Tenants
- `app/Http/Requests/Admin/StoreTenantRequest.php` (name, subdomain unique, locale)
- `app/Http/Requests/Admin/UpdateTenantRequest.php` (including changing `status`: active/suspended)
- `app/Http/Controllers/Admin/TenantController.php` → index / store / show / update
- `app/Http/Resources/Admin/TenantResource.php`

### 2.2 Subscriptions
- `app/Http/Requests/Admin/StoreSubscriptionRequest.php` (tenant_id, plan_type, status, start_date, end_date)
- `app/Http/Requests/Admin/UpdateSubscriptionRequest.php`
- `app/Http/Controllers/Admin/SubscriptionController.php` → index (filter by tenant_id) / store / update
  - one small extra action: `markAsPaid()` → PATCH that sets `marked_paid_at = now()` and `status = active`
- `app/Http/Resources/Admin/SubscriptionResource.php`

### 2.3 Routes (routes/admin.php)
```
Route::apiResource('tenants', TenantController::class)->except('destroy');
Route::apiResource('subscriptions', SubscriptionController::class)->only(['index','store','update']);
Route::patch('subscriptions/{subscription}/mark-paid', [SubscriptionController::class, 'markAsPaid']);
```
Under the existing `admin` guard.

---

## 3. Appointments Module

### 3.1 Request Validation
- `StoreAppointmentRequest`: patient_id (exists, same tenant), doctor_id (exists in users with role=doctor), scheduled_at required|date, duration_minutes required|int, notes nullable
  - `created_by` is set by the Controller from `auth()->id()`, not from the request
- `UpdateAppointmentRequest`: same fields, sometimes, without status (status has its own endpoint)
- `UpdateAppointmentStatusRequest`: `status` required|in:scheduled,completed,cancelled,no_show

### 3.2 Controller
`app/Http/Controllers/Api/V1/AppointmentController.php`
- `index()` — filter by `?patient_id=`, `?doctor_id=`, `?date_from=&date_to=`, `?status=`
- `store()`
- `show()`
- `update()` — edits appointment details (not status)
- `updateStatus()` — if the status changes and the doctor_id differs from who actually treated the patient, update `doctor_id` too (per the agreed rule that this field always reflects who actually treated the patient)

### 3.3 Resource
`app/Http/Resources/V1/AppointmentResource.php` (with patient/doctor names loaded when `with()` is requested)

### 3.4 Routes
```
Route::apiResource('appointments', AppointmentController::class);
Route::patch('appointments/{appointment}/status', [AppointmentController::class, 'updateStatus']);
```

---

## 4. Tooth Records (Odontogram) Module

This table is historical (effectively append-only) — there's no `update`; every change to a tooth's condition is a new row.

### 4.1 Request Validation
- `StoreToothRecordRequest`: patient_id, appointment_id, tooth_number required|regex matching FDI (11-48), condition required|in:healthy,decayed,filled,missing,crown,root_canal,needs_extraction,impacted, treatment_status required|in:planned,in_progress,completed, notes nullable
  - `recorded_by` from `auth()->id()`

### 4.2 Controller
`app/Http/Controllers/Api/V1/ToothRecordController.php`
- `index(Patient $patient)` — the patient's full historical records (for a history screen if needed)
- `store(Patient $patient)` — add a new record for a given tooth
- `odontogram(Patient $patient)` — dedicated endpoint returning the **latest record per tooth** (most recent row per `tooth_number`) to render the current tooth chart — the one piece of logic worth isolating into a model method: `ToothRecord::latestPerTooth($patientId)`

### 4.3 Resource
`app/Http/Resources/V1/ToothRecordResource.php`

### 4.4 Routes
```
Route::get('patients/{patient}/tooth-records', [ToothRecordController::class, 'index']);
Route::post('patients/{patient}/tooth-records', [ToothRecordController::class, 'store']);
Route::get('patients/{patient}/odontogram', [ToothRecordController::class, 'odontogram']);
```

---

## General implementation notes (apply to every module)

- Every Controller uses the existing `ApiResponse` trait (Phase 6) for consistent responses.
- The `Auditable` trait should auto-apply to `Patient`, `Appointment`, and `ToothRecord` (sensitive models) — confirm it's attached to these models if not already.
- Validating that `patient_id` / `doctor_id` / `appointment_id` belong to the same tenant happens automatically via the Global Scope + the standard `exists` rule — no need for extra manual code.
- No need for complex FormRequest Authorization logic (Policies) at this stage — use the existing role middleware at the route level, as already established in Phase 4/5.

## Verification (after each module)
```bash
php artisan route:list --path=api/v1
php artisan test   # if basic Feature tests were added
```
Manual test with Postman: full CRUD for each module + confirming a user from tenant A can't see tenant B's data.
