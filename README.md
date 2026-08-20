# Dental Clinic Management System

A multi-tenant SaaS platform for managing dental clinics. Each clinic (tenant) operates in complete isolation — patients, appointments, invoices, and inventory belong exclusively to that clinic.

## Key Features

- **Patient Management** — register and manage patient profiles, medical history, and contact info.
- **Appointments** — schedule, track, and update appointment status (scheduled, completed, cancelled, no-show).
- **Odontogram / Tooth Records** — record per-tooth conditions and treatment progress across appointments.
- **Services** — configurable price list with bilingual names (Arabic/English), including a protected "Other" service.
- **Invoices & Payments** — create invoices from services, track partial/full payments, and calculate balances.
- **Inventory** — track stock levels, minimum thresholds, and log stock movements (in/out).
- **Audit Log** — automatic history of create/update/delete actions across key entities.
- **X-Ray Attachments** — attach imaging files to patients and appointments.
- **Subscription Management** — plan types, trial/active/expired states, and payment tracking (admin).

## Roles

| Role | General Capabilities |
|------|---------------------|
| **Owner** | Full access. Can manage users, services, invoices, inventory, and delete records. |
| **Doctor** | View patients, appointments, tooth records, services, invoices, and inventory. |
| **Receptionist** | Register patients, create appointments, issue invoices, record payments, and manage inventory. |

## How It Works

- **Multi-tenancy**: Every data row (patients, appointments, invoices, etc.) belongs to a tenant via `tenant_id`. The backend enforces tenant isolation with global scopes — a clinic can never see another clinic's data.
- **Bilingual**: Services carry both `name_ar` and `name_en`. The app locale follows the clinic's preference (Arabic or English).
- **Authentication**: Tenant staff log in via email/password and receive an API token (Sanctum). Admins use a separate login at `/admin/auth/login`.

## Local Setup

```bash
# 1. Install dependencies
composer install
npm install

# 2. Environment file
cp .env.example .env
php artisan key:generate

# 3. Database
# Update .env with your database credentials, then:
php artisan migrate --force

# 4. Seed demo data (optional)
php artisan db:seed

# 5. Build assets (if needed)
npm run build

# 6. Start the server
php artisan serve
```

### Demo Credentials

| Email | Password | Role |
|-------|----------|------|
| owner@smileclinic.com | password | Owner |
| doctor@smileclinic.com | password | Doctor |
| reception@smileclinic.com | password | Receptionist |

Admin:
- Email: `superadmin@dental.com`
- Password: `Password123`

## API Version

All tenant endpoints are prefixed with `/api/v1`. Admin endpoints live under `/api/admin`.

For the full technical API reference, see [TECHNICAL.md](TECHNICAL.md).
