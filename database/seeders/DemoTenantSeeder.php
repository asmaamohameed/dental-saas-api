<?php

namespace Database\Seeders;

use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Patient;
use App\Models\Service;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Tenant
        $tenant = Tenant::firstOrCreate([
            'subdomain' => 'smileclinic',
        ], [
            'name' => 'Smile Clinic',
            'status' => 'active',
        ]);

        // 2. Create Subscription
        Subscription::firstOrCreate([
            'tenant_id' => $tenant->id,
        ], [
            'plan_type' => 'Premium',
            'start_date' => Carbon::now()->subDays(10)->toDateString(),
            'end_date' => Carbon::now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        // 3. Create Users
        $doctor = User::firstOrCreate([
            'email' => 'doctor@smileclinic.com',
        ], [
            'tenant_id' => $tenant->id,
            'name' => 'Dr. John Doe',
            'phone' => '+1234567890',
            'password_hash' => Hash::make('password'),
            'role' => 'doctor',
            'is_active' => true,
        ]);

        $receptionist = User::firstOrCreate([
            'email' => 'reception@smileclinic.com',
        ], [
            'tenant_id' => $tenant->id,
            'name' => 'Jane Smith',
            'phone' => '+1987654321',
            'password_hash' => Hash::make('password'),
            'role' => 'receptionist',
            'is_active' => true,
        ]);

        // 4. Create Patients
        $patient = Patient::firstOrCreate([
            'tenant_id' => $tenant->id,
            'full_name' => 'Alice Johnson',
        ], [
            'phone' => '+1122334455',
            'date_of_birth' => '1990-05-15',
            'gender' => 'female',
            'medical_history' => ['allergies' => ['Penicillin']],
        ]);

        // 5. Create Services
        $service = Service::firstOrCreate([
            'name_ar' => 'تنظيف أسنان',
            'tenant_id' => $tenant->id,
        ], [
            'name_en' => 'Teeth Cleaning',
            'default_price' => 150.00,
            'is_active' => true,
        ]);

        // 6. Create Appointment
        $appointment = Appointment::firstOrCreate([
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
        ], [
            'scheduled_at' => Carbon::now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'notes' => 'Regular checkup and cleaning',
        ]);

        // 7. Create Invoice
        $invoice = Invoice::firstOrCreate([
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
        ], [
            'total_amount' => 150.00,
            'status' => 'unpaid',
        ]);

        InvoiceItem::firstOrCreate([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
        ], [
            'description' => $service->name_en,
            'quantity' => 1,
            'price' => $service->default_price,
        ]);
    }
}
