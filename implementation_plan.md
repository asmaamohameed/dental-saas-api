# Dental SaaS API — Foundation Implementation Plan

Build the complete backend foundation for a multi-tenant dental clinic management system using **Laravel 12 + Sanctum + PostgreSQL**, based on the ERD and schema defined in [ERD.md](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/docs/ERD.md) and [schema.dbml](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/schema.dbml).

## Open Questions

> [!NOTE]
> All questions have been resolved:
> 1. **PostgreSQL Credentials**: Provided as `postgres`/`password`/`my_database` on `127.0.0.1:5432`.
> 2. **File Storage**: Configured to use S3/cloud storage from the beginning.
> 3. **Seeder Scope**: Creating comprehensive seeders.
> 4. **Updated_at**: Adding `updated_at` column to all tables.

---

## Completed Work

### Phase 1 — Project Configuration & Environment (Complete)

- [x] Switch `DB_CONNECTION=pgsql` and set PostgreSQL credentials in `.env` and `.env.example`
- [x] Configure S3 in `.env.example` and `.env` (`FILESYSTEM_DISK=s3`)
- [x] Add an `admin` guard + `admin_users` provider in `config/auth.php`
- [x] Generate API and Sanctum configuration using `php artisan install:api`
- [x] Create `routes/admin.php` for super-admin routes
- [x] Register `api.php`, `admin.php`, and middleware aliases in `bootstrap/app.php`

### Phase 2 — Database Migrations (Partially Complete)

- [x] `0001_01_01_000000_create_users_table.php`: Rewritten for `admin_users`, `tenants`, `users`
- [x] `0001_01_01_000001_create_subscriptions_table.php`
- [x] `0001_01_01_000002_create_patients_table.php` (with jsonb GIN index)
- [x] `0001_01_01_000003_create_services_table.php`
- [x] `0001_01_01_000004_create_appointments_table.php`
- [x] `0001_01_01_000005_create_tooth_records_table.php`
- [x] `0001_01_01_000006_create_invoices_table.php`
- [x] `0001_01_01_000007_create_invoice_items_table.php`
- [x] `0001_01_01_000008_create_payments_table.php`
- [x] `0001_01_01_000009_create_xray_attachments_table.php`
- [x] `0001_01_01_000010_create_audit_logs_table.php`
- [x] Sanctum's `personal_access_tokens` table created via install command

### Phase 3 — Eloquent Models (Complete)

- [x] [app/Models/Concerns/BelongsToTenant.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Models/Concerns/BelongsToTenant.php)
- [x] [app/Models/Concerns/Auditable.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Models/Concerns/Auditable.php)
- [x] [app/Models/AdminUser.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Models/AdminUser.php)
- [x] [app/Models/User.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Models/User.php)
- [x] `app/Models/Tenant.php`
- [x] `app/Models/Subscription.php`
- [x] `app/Models/Patient.php`
- [x] `app/Models/Appointment.php`
- [x] `app/Models/ToothRecord.php`
- [x] `app/Models/Service.php`
- [x] `app/Models/Invoice.php`
- [x] `app/Models/InvoiceItem.php`
- [x] `app/Models/Payment.php`
- [x] `app/Models/XrayAttachment.php`
- [x] `app/Models/AuditLog.php`

### Phase 4 — Middleware & Multi-Tenancy Infrastructure (Complete)

- [x] [app/Http/Middleware/EnsureTenantAccess.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Middleware/EnsureTenantAccess.php)
- [x] [app/Http/Middleware/EnsureUserRole.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Middleware/EnsureUserRole.php)
- [x] [app/Http/Middleware/SetLocale.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Middleware/SetLocale.php)

---

## Pending Work to Execute



---



### Phase 5 — Base API Structure & Auth Endpoints

#### [NEW] [app/Http/Controllers/Api/V1/Auth/LoginController.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Controllers/Api/V1/Auth/LoginController.php)
#### [NEW] [app/Http/Controllers/Api/V1/Auth/LogoutController.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Controllers/Api/V1/Auth/LogoutController.php)
#### [NEW] [app/Http/Controllers/Api/V1/Auth/ProfileController.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Controllers/Api/V1/Auth/ProfileController.php)
#### [NEW] [app/Http/Controllers/Admin/Auth/LoginController.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Controllers/Admin/Auth/LoginController.php)
#### [NEW] [app/Http/Resources/V1/UserResource.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Resources/V1/UserResource.php)
#### [NEW] [app/Http/Resources/V1/TenantResource.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Resources/V1/TenantResource.php)
#### [NEW] [app/Http/Requests/V1/Auth/LoginRequest.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Http/Requests/V1/Auth/LoginRequest.php)

---

### Phase 6 — API Response Helpers & Exception Handling

#### [NEW] [app/Traits/ApiResponse.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/app/Traits/ApiResponse.php)
#### [MODIFY] [bootstrap/app.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/bootstrap/app.php)
- Configure exception handler to return JSON for all API routes

---

### Phase 7 — Database Seeders

#### [NEW] [database/seeders/AdminUserSeeder.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/database/seeders/AdminUserSeeder.php)
#### [NEW] [database/seeders/DemoTenantSeeder.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/database/seeders/DemoTenantSeeder.php)
#### [MODIFY] [database/seeders/DatabaseSeeder.php](file:///d:/Backend%20Projects/Dental%20Project/dental-saas-api/database/seeders/DatabaseSeeder.php)

---

## Verification Plan

### Automated Tests
```bash
php artisan migrate:fresh --seed    # All 13 tables created, seeders run without error
php artisan tinker                  # Verify model relationships manually
php artisan route:list              # Verify all routes are registered
```

### Manual Verification
- Test login flow with Postman/Insomnia: `POST /api/v1/auth/login` → get token → `GET /api/v1/auth/me` with `Authorization: Bearer {token}`
- Test admin login: `POST /admin/auth/login`
- Verify tenant isolation: create two tenants, login as user from tenant A, confirm they cannot see tenant B's data
- Verify audit log: update an appointment, check `audit_logs` table for the diff entry
