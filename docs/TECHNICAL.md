# Backend Technical Guide

Audience: frontend developers and anyone integrating with the API. Covers architecture, database, auth, endpoints, request/response shapes, and frontend gotchas.

## 1. Architecture Overview

### Multi-Tenancy

- Every "tenant" model (Patient, Appointment, Invoice, Service, etc.) uses the `BelongsToTenant` trait.
- A **global query scope** automatically appends `WHERE tenant_id = <current>` to every query on those models. Querying without a tenant context throws a runtime exception.
- On **create**, the trait sets `tenant_id` automatically from the current tenant resolver.
- Tenant context is set by the `EnsureTenantAccess` middleware after the user is authenticated.

### Tenant Isolation Rules

- Tenant data is isolated at the query level via global scopes.
- Foreign keys from tenant models to other tenant models cascade delete on tenant removal.
- Admin users are **not** tenants. They live in a separate `admin_users` table and use a separate guard (`admin`).

### Folder Structure

```
app/
  Enums/              # Value objects (UserRole, AppointmentStatus, etc.)
  Exceptions/         # Custom exceptions (InvoiceHasPaymentsException, ServiceProtectedException)
  Http/
    Controllers/
      Admin/          # Admin-only controllers (Tenant, Subscription)
      Api/V1/         # Tenant API controllers
      Api/V1/Auth/    # Login, logout, profile, password reset
    Middleware/        # tenant, is_admin, role, locale
    Requests/V1/      # Form Request validation classes
    Resources/V1/     # API Resources (JSON transformation)
  Models/             # Eloquent models
  Policies/           # Authorization policies
  Services/           # Business logic (InvoiceService, PaymentService, etc.)
  Support/Tenancy/    # CurrentTenant singleton
  Traits/             # ApiResponse trait
database/
  migrations/         # Schema definitions
  seeders/            # AdminUserSeeder, DemoTenantSeeder, TreatmentTemplateSeeder
routes/
  api.php             # Tenant API routes (v1)
  admin.php           # Admin API routes
```

## 2. Database Schema

### Core Tables

| Table | Key Columns / Notes |
|-------|---------------------|
| **tenants** | `id`, `name`, `subdomain` (unique), `locale` (default `ar`), `status` |
| **users** | `id`, `tenant_id`, `name`, `email` (globally unique), `phone`, `password_hash`, `role`, `locale`, `is_active` |
| **admin_users** | `id`, `name`, `email`, `password_hash`, `is_active` (separate from tenants) |
| **patients** | `id`, `tenant_id`, `full_name`, `phone`, `date_of_birth`, `gender`, `medical_history` (jsonb), `notes` |
| **appointments** | `id`, `tenant_id`, `patient_id`, `doctor_id` (users), `created_by`, `scheduled_at`, `duration_minutes`, `status`, `appointment_type`, `notes` |
| **tooth_records** | `id`, `tenant_id`, `patient_id`, `appointment_id`, `recorded_by`, `tooth_number` (FDI), `condition`, `treatment_status`, `notes` |
| **components** | `id`, `tenant_id`, `name_ar`, `name_en`, `default_price`, `unit`, `current_quantity`, `minimum_threshold`, `inventory_item_id`, `is_active` |
| **treatment_templates** | Versioned catalog: `version`, `is_current`, `root_template_id`, `previous_version_id`. Edits create a new version. |
| **treatment_template_steps** | Ordered steps (`is_optional`, `is_repeatable`, `default_duration_minutes`) plus per-step components |
| **treatment_plans** | Optional grouping of patient treatments (`draft`, `active`, `completed`, `cancelled`) |
| **patient_treatments** | Independent `clinical_status`, `financial_status`, `consent_status`; `agreed_price` snapshot; optional `parent_treatment_id` for retreatment |
| **patient_treatment_teeth** | Junction: 0, 1, or many FDI teeth per treatment |
| **treatment_sessions** | Clinical visits (`scheduled`, `completed`, `cancelled`, `no_show`) optionally linked to an appointment |
| **treatment_session_steps** | Done/skipped occurrences of a template step or a custom step (`occurrence_number`) |
| **patient_treatment_components** | Materials copied onto a session at complete, then deducted from stock |
| **services** | `id`, `tenant_id`, `name_ar`, `name_en`, `default_price`, `is_active`, `is_other` |
| **invoices** | `id`, `tenant_id`, `patient_id`, `appointment_id`, `created_by`, `total_amount`, `status`, soft deletes |
| **invoice_items** | `id`, `tenant_id`, `invoice_id`, `service_id`, `patient_treatment_id` (nullable), `description`, `price`, `quantity`, soft deletes |
| **payments** | `id`, `tenant_id`, `invoice_id`, `amount`, `paid_at`, `method`, `received_by`, `notes`, soft deletes |
| **inventory_items** | `id`, `tenant_id`, `name`, `unit`, `current_quantity`, `minimum_threshold`, `is_active` |
| **inventory_transactions** | `id`, `tenant_id`, `inventory_item_id`, `type`, `quantity`, `reason`, `performed_by` |
| **audit_logs** | `id`, `tenant_id`, `user_id`, `action`, `auditable_type`, `auditable_id`, `old_values`, `new_values` |
| **subscriptions** | `id`, `tenant_id`, `plan_type`, `status`, `start_date`, `end_date`, `marked_paid_at` |

### Important Relationship Notes

- **`tooth_records` is append-only history**. The `odontogram` endpoint returns `{ records, treatment_status_by_tooth }`. `records` is the *latest* condition per tooth (window function); `treatment_status_by_tooth` is derived from patient treatments (failed > in_progress/on_hold > planned > completed; cancelled ignored). Conditions are never overwritten by treatments.
- **Treatment tracking is five levels**: Template → optional Plan → Patient Treatment → Session → Session Step. Clinical, financial, consent, and appointment statuses are independent.
- **Template versions are immutable**. Updating a current template inserts `version+1` with `is_current = true` and leaves existing patient treatments on the old id.
- **Billing**: creating a patient treatment snapshots `agreed_price` and opens one invoice line. Payments recompute `financial_status` without changing `clinical_status`.
- **Stock**: completing a session copies the template-step components onto the session, then deducts quantity from `components` / inventory.
- **`invoice_items.service_id` is non-nullable** and references `services.id`. A fixed "Other" service row exists per tenant for ad-hoc charges.
- **`users.email` is globally unique** across the entire system, not just per tenant.
- **`inventory_items` has a unique constraint on `(tenant_id, name)`**.
- **`invoices`** are soft-deletable; a cancelled invoice sets `status = cancelled` then deletes the row. Invoices with recorded payments cannot be deleted (throws `InvoiceHasPaymentsException`).
- **`payments`** and **`invoice_items`** are soft-deletable.

## 3. Authentication & Authorization

### Guards

| Guard | Model | Table | Used For |
|-------|-------|-------|----------|
| `web` | `User` | `users` | Tenant staff (owner, doctor, receptionist) |
| `admin` | `AdminUser` | `admin_users` | Platform super-admin |

### Tenant Login Flow

1. `POST /api/v1/auth/login` with `email`, `password`, optional `device_name`.
2. Backend validates credentials against the `users` table (global email lookup).
3. If valid and `is_active = true`, a Sanctum token is returned.
4. Subsequent requests use `Authorization: Bearer <token>`.

### Admin Login Flow

1. `POST /api/admin/auth/login` with `email`, `password`, optional `device_name`.
2. Backend validates against `admin_users`.
3. Returns a Sanctum token with the `admin` guard.

### Roles

Enums live in `app/Enums/UserRole.php`:

- `owner`
- `doctor`
- `receptionist`

### Authorization

- **Policies** are auto-discovered in `app/Policies/`.
- The `EnsureUserRole` middleware restricts route groups to specific roles.
- Many policies use a `before()` method to grant owners blanket access.
- Route-level protection:
  - `auth:sanctum` + `tenant` middleware on all protected tenant routes.
  - `auth:sanctum` + `is_admin` middleware on all admin routes.

## 4. API Endpoints

All tenant endpoints are under `/api/v1`. Admin endpoints are under `/api/admin`.

### Response Format

**Success:**
```json
{
  "status": "success",
  "message": "...",
  "data": { ... }
}
```

**Paginated:**
```json
{
  "status": "success",
  "message": "...",
  "data": {
    "items": [ ... ],
    "meta": {
      "current_page": 1,
      "last_page": 5,
      "per_page": 15,
      "total": 75
    }
  }
}
```

**Error:**
```json
{
  "status": "error",
  "message": "Validation error",
  "errors": {
    "field": ["rule"]
  }
}
```

Validation errors return HTTP 422. Authentication errors return 401. Authorization errors return 403.

### Tenant Routes (`/api/v1`)

#### Auth
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| POST | `/auth/login` | No | — |
| POST | `/auth/logout` | Yes | — |
| GET | `/auth/me` | Yes | — |
| POST | `/auth/forgot-password` | No | — |
| POST | `/auth/reset-password` | No | — |

#### Patients
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/patients` | Yes | doctor, receptionist, owner |
| POST | `/patients` | Yes | doctor, receptionist, owner |
| GET | `/patients/{patient}` | Yes | doctor, receptionist, owner |
| PUT/PATCH | `/patients/{patient}` | Yes | doctor, receptionist, owner |
| DELETE | `/patients/{patient}` | Yes | receptionist only (policy) |

Patient search: `GET /patients?search=alice` (searches `full_name` and `phone`).

#### Appointments
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/appointments` | Yes | doctor, receptionist, owner |
| POST | `/appointments` | Yes | doctor, receptionist, owner |
| GET | `/appointments/{appointment}` | Yes | doctor, receptionist, owner |
| PUT/PATCH | `/appointments/{appointment}` | Yes | doctor, receptionist, owner |
| DELETE | `/appointments/{appointment}` | Yes | doctor, receptionist, owner |
| PATCH | `/appointments/{appointment}/status` | Yes | doctor, receptionist, owner |

Filters: `patient_id`, `doctor_id`, `status`, `date_from`, `date_to`.

#### Tooth Records & Odontogram
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/patients/{patient}/tooth-records` | Yes | doctor, receptionist, owner |
| POST | `/patients/{patient}/tooth-records` | Yes | doctor, receptionist, owner |
| GET | `/patients/{patient}/odontogram` | Yes | doctor, receptionist, owner |

The `odontogram` endpoint is not a flat list. It returns:

```json
{
  "records": [{ "tooth_number": "11", "condition": "filled", "...": "..." }],
  "treatment_status_by_tooth": { "11": "in_progress" }
}
```

#### Treatment templates
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/treatment-templates` | Yes | doctor, receptionist, owner |
| GET | `/treatment-templates/{template}` | Yes | doctor, receptionist, owner |
| GET | `/treatment-templates/{template}/versions` | Yes | doctor, receptionist, owner |
| POST | `/treatment-templates` | Yes | owner, receptionist |
| PUT/PATCH | `/treatment-templates/{template}` | Yes | owner, receptionist |
| PATCH | `/treatment-templates/{template}/toggle-active` | Yes | owner, receptionist |
| DELETE | `/treatment-templates/{template}` | Yes | owner only |

PUT creates a new version. DELETE deactivates if any patient treatment in the version family exists.

#### Treatment plans
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/patients/{patient}/treatment-plans` | Yes | doctor, receptionist, owner |
| POST | `/patients/{patient}/treatment-plans` | Yes | owner, receptionist |
| PUT | `/treatment-plans/{plan}` | Yes | owner, receptionist |
| DELETE | `/treatment-plans/{plan}` | Yes | owner only |

#### Patient treatments & sessions
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/patient-treatments` | Yes | doctor, receptionist, owner |
| GET | `/patients/{patient}/treatments` | Yes | doctor, receptionist, owner |
| GET | `/patients/{patient}/next-treatment` | Yes | doctor, receptionist, owner |
| POST | `/patient-treatments` | Yes | owner, receptionist |
| PUT | `/patient-treatments/{treatment}` | Yes | owner, receptionist |
| PATCH | `/patient-treatments/{treatment}/status` | Yes | owner, receptionist, doctor |
| POST | `/patient-treatments/{treatment}/retreat` | Yes | owner, receptionist, doctor |
| DELETE | `/patient-treatments/{treatment}` | Yes | owner only |
| GET | `/patient-treatments/{treatment}/invoice-summary` | Yes | doctor, receptionist, owner |
| POST | `/patient-treatments/{treatment}/sessions` | Yes | doctor, receptionist, owner |
| PATCH | `/treatment-sessions/{session}` | Yes | doctor, receptionist, owner |
| POST | `/treatment-sessions/{session}/steps` | Yes | doctor, receptionist, owner |
| PATCH | `/treatment-session-steps/{step}` | Yes | doctor, receptionist, owner |
| DELETE | `/treatment-session-steps/{step}` | Yes | doctor, receptionist, owner |
| POST | `/invoices/from-treatment/{treatment}` | Yes | owner, receptionist |

`clinical_status` values: `planned`, `in_progress`, `on_hold`, `completed`, `cancelled`, `failed`. Cancelling requires `cancellation_reason`. Completion is automatic once every required (non-optional) template step has a `done` occurrence. Appointments may include `patient_treatment_ids`; cancelled / no-show / completed appointments update linked **sessions only**, not clinical status.

#### Services
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/services` | Yes | doctor, receptionist, owner |
| GET | `/services/{service}` | Yes | doctor, receptionist, owner |
| POST | `/services` | Yes | owner, receptionist |
| PUT/PATCH | `/services/{service}` | Yes | owner, receptionist |
| PATCH | `/services/{service}/toggle-active` | Yes | owner, receptionist |
| DELETE | `/services/{service}` | Yes | owner only |

Filters: `is_active` (boolean), `search` (searches `name_ar` and `name_en`).

#### Invoices
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/invoices` | Yes | doctor, receptionist, owner |
| GET | `/invoices/{invoice}` | Yes | doctor, receptionist, owner |
| POST | `/invoices` | Yes | owner, receptionist |
| PUT/PATCH | `/invoices/{invoice}` | Yes | owner, receptionist |
| DELETE | `/invoices/{invoice}` | Yes | owner only |
| GET | `/patients/{patient}/invoices-summary` | Yes | doctor, receptionist, owner |

Filters: `status`, `patient_id`, `date_from`, `date_to`.

#### Invoice Items
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/invoices/{invoice}/items` | Yes | doctor, receptionist, owner |
| POST | `/invoices/{invoice}/items` | Yes | owner, receptionist |
| PUT/PATCH | `/invoices/{invoice}/items/{item}` | Yes | owner, receptionist |
| DELETE | `/invoices/{invoice}/items/{item}` | Yes | owner, receptionist |

#### Payments
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/invoices/{invoice}/payments` | Yes | doctor, receptionist, owner |
| GET | `/invoices/{invoice}/payments/{payment}` | Yes | doctor, receptionist, owner |
| POST | `/invoices/{invoice}/payments` | Yes | owner, receptionist |
| PUT/PATCH | `/invoices/{invoice}/payments/{payment}` | Yes | owner only |
| DELETE | `/invoices/{invoice}/payments/{payment}` | Yes | owner only |

#### Inventory
| Method | Path | Auth | Roles |
|--------|------|------|-------|
| GET | `/inventory-items` | Yes | doctor, receptionist, owner |
| POST | `/inventory-items` | Yes | owner only |
| GET | `/inventory-items/{inventoryItem}` | Yes | doctor, receptionist, owner |
| PUT/PATCH | `/inventory-items/{inventoryItem}` | Yes | owner only |
| DELETE | `/inventory-items/{inventoryItem}` | Yes | owner only (deactivates) |
| PATCH | `/inventory-items/{inventoryItem}/restore` | Yes | owner only |
| GET | `/inventory-items/{item}/transactions` | Yes | doctor, receptionist, owner |
| POST | `/inventory-items/{item}/transactions` | Yes | doctor, receptionist, owner |

Inventory index filtering: `low_stock` (boolean).

### Admin Routes (`/api/admin`)

| Method | Path | Auth | Roles |
|--------|------|------|-------|
| POST | `/auth/login` | No | — |
| GET | `/ping` | Yes | admin |
| GET | `/tenants` | Yes | admin |
| POST | `/tenants` | Yes | admin |
| GET | `/tenants/{tenant}` | Yes | admin |
| PUT/PATCH | `/tenants/{tenant}` | Yes | admin |
| GET | `/subscriptions` | Yes | admin |
| POST | `/subscriptions` | Yes | admin |
| PUT/PATCH | `/subscriptions/{subscription}` | Yes | admin |
| PATCH | `/subscriptions/{subscription}/mark-paid` | Yes | admin |

## 5. Request & Response Shapes

### Login Request

```json
POST /api/v1/auth/login
{
  "email": "doctor@smileclinic.com",
  "password": "password",
  "device_name": "web"
}
```

**Response:**
```json
{
  "status": "success",
  "message": "Successfully logged in.",
  "data": {
    "access_token": "...",
    "user": {
      "id": "...",
      "name": "Dr. John Doe",
      "email": "doctor@smileclinic.com",
      "phone": "+1234567890",
      "role": "doctor",
      "locale": "en",
      "is_active": true,
      "tenant": {
        "id": "...",
        "name": "Smile Clinic",
        "subdomain": "smileclinic",
        "locale": "ar",
        "status": "active"
      }
    }
  }
}
```

### Create Appointment

```json
POST /api/v1/appointments
{
  "patient_id": "...",
  "doctor_id": "...",
  "scheduled_at": "2026-08-25T10:00:00Z",
  "duration_minutes": 30,
  "notes": "Regular checkup"
}
```

**Response includes:** `id`, `patient_id`, `doctor_id`, `scheduled_at`, `duration_minutes`, `status` (default: `scheduled`), `appointment_type`, `notes`, `patient` (id + name), `doctor` (id + name), `treatment_sessions` when loaded. Optional `patient_treatment_ids` on create/update schedules sessions for those treatments.

### Create Invoice

```json
POST /api/v1/invoices
{
  "patient_id": "...",
  "appointment_id": "...",
  "items": [
    {
      "service_id": "...",
      "description": "...",
      "quantity": 1
    }
  ]
}
```

- `service_id` must belong to the current tenant.
- If the service has `is_other = true`, `description` is required and `price` overrides `default_price`.
- The server calculates `total_amount` automatically.

**Response includes:** `id`, `total_amount`, `remaining_amount`, `status` (default: `unpaid`), `patient`, `appointment`, `items`, `payments`.

### Odontogram Response

```json
{
  "status": "success",
  "data": {
    "records": [
      {
        "id": "...",
        "patient_id": "...",
        "tooth_number": "11",
        "condition": "healthy",
        "treatment_status": "planned",
        "notes": null,
        "recorded_by": "...",
        "created_at": "..."
      }
    ],
    "treatment_status_by_tooth": {
      "11": "in_progress"
    }
  }
}
```

`records` is the most recent **condition** row per tooth (1–48). `treatment_status_by_tooth` is derived from open/completed patient treatments (failed > in_progress > planned > completed). No pagination.

### Create Patient Treatment

```json
POST /api/v1/patient-treatments
{
  "patient_id": "...",
  "treatment_template_id": "...",
  "tooth_numbers": ["16"],
  "agreed_price": 1500,
  "consent_status": "not_required"
}
```

Creates the treatment, snapshots `agreed_price`, and opens one invoice line. `tooth_numbers` may be empty, one, or many.

### Inventory Transaction Request

```json
POST /api/v1/inventory-items/{item}/transactions
{
  "type": "out",
  "quantity": 2.5,
  "reason": "stock count"
}
```

- `type` must be `in` or `out`.
- For `out`, quantity cannot exceed current stock.
- Inactive items reject transactions with a 422 error.

## 6. i18n Support

- **Services**: returned with both `name_ar` and `name_en`. The frontend picks based on locale.
- **Status / Role / Condition values**: always returned as fixed lowercase English strings. The frontend should translate them via `next-intl` or equivalent.
  - Appointment status: `scheduled`, `completed`, `cancelled`, `no_show`
  - Patient treatment clinical status: `planned`, `in_progress`, `on_hold`, `completed`, `cancelled`, `failed`
  - Financial status: `unpaid`, `partial`, `paid`
  - Consent status: `not_required`, `pending`, `obtained`, `declined`
  - Session status: `scheduled`, `completed`, `cancelled`, `no_show`
  - Invoice status: `unpaid`, `partial`, `paid`, `cancelled`
  - User role: `owner`, `doctor`, `receptionist`
  - Tooth condition: `healthy`, `decayed`, `filled`, `missing`, `crown`, `root_canal`, `needs_extraction`, `impacted`
  - Tooth treatment status: `planned`, `in_progress`, `completed`
  - Inventory transaction type: `in`, `out`
  - Subscription status: `trial`, `active`, `expired`
  - Audit action: `created`, `updated`, `deleted`, `restored`
  - Payment method: `cash`, `card`, `transfer`, `other`
- **Locale precedence**:
  1. Authenticated user's `locale` preference.
  2. Tenant's `locale`.
  3. `Accept-Language` header (first two letters).
  4. Fallback: `en` (configurable via `APP_LOCALE`).

## 7. Things the Frontend Needs to Be Aware Of

### Validation & Errors

- Validation errors follow Laravel's standard structure inside the `errors` key.
- Custom business errors (e.g., "Invoice has payments", "Service is protected") return 422 with a `message` field.

### Pagination

- Default page size is 15 on most endpoints.
- Maximums vary:
  - Patients, appointments, tooth records, inventory: max **100**
  - Services, invoices: max **50**
  - Admin tenants/subscriptions: max **100**
- Omitted `per_page` uses the default. Always expect `meta` in paginated responses.

### Audit Log

- Automatic for models using the `Auditable` trait: Patient, Appointment, ToothRecord, Invoice, Payment, XrayAttachment.
- Not automatic for Service, InventoryItem, InventoryTransaction, Subscription, Tenant (no `Auditable` trait).
- Audit log entries are not exposed via a dedicated controller endpoint in the current API. They are available internally.

### Inventory Specifics

- `InventoryItem` has `is_active`. The index endpoint **only returns active items**.
- Transactions on inactive items are rejected with a 422 error.
- `is_low_stock` is computed in the API resource: true when `current_quantity <= minimum_threshold`.

### Invoice & Payment Business Rules

- Fully paid invoices (`status = paid`) cannot have items added/modified/deleted.
- Partially paid invoices cannot be updated via the invoice update endpoint (owner can still edit payments).
- Deleting an invoice with payments throws `InvoiceHasPaymentsException`.
- Payment amount cannot exceed the invoice's remaining balance.
- The "Other" service is system-reserved: it cannot be deactivated, deleted, or have its name changed.

### Soft Deletes

- `invoices`, `invoice_items`, and `payments` support soft deletes.
- Deleting a patient is blocked if they have linked appointments, tooth records, invoices, or x-ray attachments.

### Security

- Never expose `password_hash` — it is hidden in `User` and `AdminUser` serialization.
- All tenant endpoints require a valid Sanctum token in the `Authorization: Bearer` header.
- Admin endpoints require a valid admin Sanctum token.
