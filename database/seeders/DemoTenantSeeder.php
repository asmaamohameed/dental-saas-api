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
            'domain' => 'smileclinic',
        ], [
            'name' => 'Smile Clinic',
            'is_active' => true,
        ]);

        // 2. Create Subscription
        Subscription::firstOrCreate([
            'tenant_id' => $tenant->id,
        ], [
            'plan_name' => 'Premium',
            'starts_at' => Carbon::now()->subDays(10),
            'ends_at' => Carbon::now()->addYear(),
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
            'email' => 'patient@example.com',
        ], [
            'tenant_id' => $tenant->id,
            'first_name' => 'Alice',
            'last_name' => 'Johnson',
            'phone' => '+1122334455',
            'date_of_birth' => '1990-05-15',
            'gender' => 'female',
            'medical_history' => ['allergies' => ['Penicillin']],
        ]);

        // 5. Create Services
        $service = Service::firstOrCreate([
            'name' => 'Teeth Cleaning',
            'tenant_id' => $tenant->id,
        ], [
            'description' => 'Standard ultrasonic cleaning and polishing',
            'price' => 150.00,
            'duration_minutes' => 30,
        ]);

        // 6. Create Appointment
        $appointment = Appointment::firstOrCreate([
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
        ], [
            'scheduled_at' => Carbon::now()->addDays(2)->setHour(10)->setMinute(0)->setSecond(0),
            'status' => 'scheduled',
            'notes' => 'Regular checkup and cleaning',
        ]);

        // 7. Create Invoice
        $invoice = Invoice::firstOrCreate([
            'tenant_id' => $tenant->id,
            'patient_id' => $patient->id,
            'appointment_id' => $appointment->id,
        ], [
            'subtotal' => 150.00,
            'tax' => 15.00,
            'discount' => 0.00,
            'total' => 165.00,
            'status' => 'unpaid',
            'issued_at' => Carbon::now(),
            'due_at' => Carbon::now()->addDays(30),
        ]);

        InvoiceItem::firstOrCreate([
            'invoice_id' => $invoice->id,
            'service_id' => $service->id,
        ], [
            'description' => $service->name,
            'quantity' => 1,
            'unit_price' => $service->price,
            'total_price' => $service->price,
        ]);
    }
}
