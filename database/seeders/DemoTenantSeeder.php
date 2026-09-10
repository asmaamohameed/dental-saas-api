<?php

namespace Database\Seeders;

use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Performance Test Configuration
        |--------------------------------------------------------------------------
        */

        $tenantCount = 10;

        $patientsPerTenant = 10_000;
        $appointmentsPerTenant = 20_000;
        $invoicesPerTenant = 20_000;

        $servicesPerTenant = 20;

        // Insert this many records at a time
        $chunkSize = 1_000;

        /*
        |--------------------------------------------------------------------------
        | Shared values
        |--------------------------------------------------------------------------
        */

        $passwordHash = Hash::make('password');

        /*
        |--------------------------------------------------------------------------
        | Create Tenants
        |--------------------------------------------------------------------------
        */

        $tenants = [];

        for ($i = 1; $i <= $tenantCount; $i++) {
            $subdomain = "performance-clinic-{$i}";

            $existingTenant = DB::table('tenants')
                ->where('subdomain', $subdomain)
                ->first();

            if ($existingTenant) {
                $tenantId = $existingTenant->id;
            } else {
                $tenantId = DB::table('tenants')->insertGetId([
                    'name' => "Performance Clinic {$i}",
                    'subdomain' => $subdomain,
                    'status' => 'active',
                ]);
            }

            $tenants[] = $tenantId;
        }

        /*
        |--------------------------------------------------------------------------
        | Generate data for every tenant
        |--------------------------------------------------------------------------
        */

        foreach ($tenants as $tenantIndex => $tenantId) {
            $this->command->info(
                'Seeding tenant '.($tenantIndex + 1)."/{$tenantCount} (ID: {$tenantId})"
            );

            /*
            |--------------------------------------------------------------------------
            | Set Current Tenant
            |--------------------------------------------------------------------------
            */

            app(CurrentTenant::class)->set($tenantId);

            /*
            |--------------------------------------------------------------------------
            | Subscription
            |--------------------------------------------------------------------------
            */

            DB::table('subscriptions')->updateOrInsert(
                [
                    'tenant_id' => $tenantId,
                ],
                [
                    'plan_type' => 'Premium',
                    'start_date' => Carbon::now()->subDays(10)->toDateString(),
                    'end_date' => Carbon::now()->addYear()->toDateString(),
                    'status' => 'active',
                ]
            );

            /*
            |--------------------------------------------------------------------------
            | Users
            |--------------------------------------------------------------------------
            */

            $ownerEmail = "owner{$tenantIndex}@performance-clinic.com";
            $doctorEmail = "doctor{$tenantIndex}@performance-clinic.com";
            $receptionEmail = "reception{$tenantIndex}@performance-clinic.com";

            $owner = DB::table('users')
                ->where('email', $ownerEmail)
                ->first();

            if (! $owner) {
                $ownerId = DB::table('users')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => "Owner {$tenantIndex}",
                    'email' => $ownerEmail,
                    'phone' => "+2010000000{$tenantIndex}",
                    'password_hash' => $passwordHash,
                    'role' => 'owner',
                    'is_active' => true,
                ]);
            } else {
                $ownerId = $owner->id;
            }

            $doctor = DB::table('users')
                ->where('email', $doctorEmail)
                ->first();

            if (! $doctor) {
                $doctorId = DB::table('users')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => "Dr. Doctor {$tenantIndex}",
                    'email' => $doctorEmail,
                    'phone' => "+2011000000{$tenantIndex}",
                    'password_hash' => $passwordHash,
                    'role' => 'doctor',
                    'is_active' => true,
                ]);
            } else {
                $doctorId = $doctor->id;
            }

            $receptionist = DB::table('users')
                ->where('email', $receptionEmail)
                ->first();

            if (! $receptionist) {
                $receptionistId = DB::table('users')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name' => "Receptionist {$tenantIndex}",
                    'email' => $receptionEmail,
                    'phone' => "+2012000000{$tenantIndex}",
                    'password_hash' => $passwordHash,
                    'role' => 'receptionist',
                    'is_active' => true,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Services
            |--------------------------------------------------------------------------
            */

            $serviceIds = [];

            for ($i = 1; $i <= $servicesPerTenant; $i++) {
                $nameAr = "خدمة أسنان {$i} - عيادة {$tenantIndex}";

                $existingService = DB::table('services')
                    ->where('tenant_id', $tenantId)
                    ->where('name_ar', $nameAr)
                    ->first();

                if ($existingService) {
                    $serviceIds[] = $existingService->id;

                    continue;
                }

                $serviceIds[] = DB::table('services')->insertGetId([
                    'tenant_id' => $tenantId,
                    'name_ar' => $nameAr,
                    'name_en' => "Dental Service {$i}",
                    'default_price' => 100 + ($i * 25),
                    'is_active' => true,
                ]);
            }

            /*
            |--------------------------------------------------------------------------
            | Patients
            |--------------------------------------------------------------------------
            */

            $this->command->info(
                "Creating {$patientsPerTenant} patients..."
            );

            $patientIds = [];

            $existingPatientCount = DB::table('patients')
                ->where('tenant_id', $tenantId)
                ->count();

            if ($existingPatientCount < $patientsPerTenant) {
                $remainingPatients =
                    $patientsPerTenant - $existingPatientCount;

                $maxPatientId = DB::table('patients')->max('id') ?? 0;

                for ($offset = 0; $offset < $remainingPatients; $offset += $chunkSize) {
                    $count = min(
                        $chunkSize,
                        $remainingPatients - $offset
                    );

                    $rows = [];

                    for ($i = 1; $i <= $count; $i++) {
                        $number = $existingPatientCount + $offset + $i;

                        $rows[] = [
                            'tenant_id' => $tenantId,
                            'full_name' => "Test Patient {$tenantIndex}-{$number}",
                            'phone' => '+201'.str_pad(
                                (string) $number,
                                9,
                                '0',
                                STR_PAD_LEFT
                            ),
                            'date_of_birth' => Carbon::now()
                                ->subYears(20 + ($number % 50))
                                ->subDays($number % 365)
                                ->toDateString(),
                            'gender' => $number % 2 === 0
                                ? 'male'
                                : 'female',
                            'medical_history' => json_encode([
                                'allergies' => [],
                                'notes' => 'Performance test patient',
                            ]),
                        ];
                    }

                    DB::table('patients')->insert($rows);
                }

                $patientIds = DB::table('patients')
                    ->where('tenant_id', $tenantId)
                    ->where('id', '>', $maxPatientId)
                    ->orderBy('id')
                    ->pluck('id')
                    ->toArray();
            } else {
                $patientIds = DB::table('patients')
                    ->where('tenant_id', $tenantId)
                    ->orderBy('id')
                    ->limit($patientsPerTenant)
                    ->pluck('id')
                    ->toArray();
            }

            /*
            |--------------------------------------------------------------------------
            | Appointments
            |--------------------------------------------------------------------------
            */

            $this->command->info(
                "Creating {$appointmentsPerTenant} appointments..."
            );

            $existingAppointmentCount = DB::table('appointments')
                ->where('tenant_id', $tenantId)
                ->count();

            if ($existingAppointmentCount < $appointmentsPerTenant) {
                $remainingAppointments =
                    $appointmentsPerTenant - $existingAppointmentCount;

                for (
                    $offset = 0;
                    $offset < $remainingAppointments;
                    $offset += $chunkSize
                ) {
                    $count = min(
                        $chunkSize,
                        $remainingAppointments - $offset
                    );

                    $rows = [];

                    for ($i = 0; $i < $count; $i++) {
                        $number = $existingAppointmentCount + $offset + $i;

                        $patientId = $patientIds[
                            $number % count($patientIds)
                        ];

                        $scheduledAt = Carbon::now()
                            ->subDays($number % 365)
                            ->setHour(9 + ($number % 9))
                            ->setMinute(0)
                            ->setSecond(0);

                        $rows[] = [
                            'tenant_id' => $tenantId,
                            'patient_id' => $patientId,
                            'doctor_id' => $doctorId,
                            'scheduled_at' => $scheduledAt,
                            'duration_minutes' => 30,
                            'status' => 'scheduled',
                            'notes' => 'Performance test appointment',
                        ];
                    }

                    DB::table('appointments')->insert($rows);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Get appointment IDs
            |--------------------------------------------------------------------------
            */

            $appointmentIds = DB::table('appointments')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->limit($appointmentsPerTenant)
                ->pluck('id')
                ->toArray();

            /*
            |--------------------------------------------------------------------------
            | Invoices
            |--------------------------------------------------------------------------
            */

            $this->command->info(
                "Creating {$invoicesPerTenant} invoices..."
            );

            $existingInvoiceCount = DB::table('invoices')
                ->where('tenant_id', $tenantId)
                ->count();

            if ($existingInvoiceCount < $invoicesPerTenant) {
                $remainingInvoices =
                    $invoicesPerTenant - $existingInvoiceCount;

                for (
                    $offset = 0;
                    $offset < $remainingInvoices;
                    $offset += $chunkSize
                ) {
                    $count = min(
                        $chunkSize,
                        $remainingInvoices - $offset
                    );

                    $rows = [];

                    for ($i = 0; $i < $count; $i++) {
                        $number = $existingInvoiceCount + $offset + $i;

                        $appointmentId = $appointmentIds[
                            $number % count($appointmentIds)
                        ];

                        $patientId = $patientIds[
                            $number % count($patientIds)
                        ];

                        $price = 150 + (($number % 10) * 25);

                        $rows[] = [
                            'tenant_id' => $tenantId,
                            'patient_id' => $patientId,
                            'appointment_id' => $appointmentId,
                            'total_amount' => $price,
                            'status' => $number % 3 === 0
                                ? 'paid'
                                : 'unpaid',
                        ];
                    }

                    DB::table('invoices')->insert($rows);
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Invoice Items
            |--------------------------------------------------------------------------
            */

            $this->command->info('Creating invoice items...');

            $invoiceIds = DB::table('invoices')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->limit($invoicesPerTenant)
                ->pluck('id')
                ->toArray();

            /*
             * We create one item for each invoice.
             * This keeps the dataset large without creating
             * unnecessarily huge numbers of child records.
             */

            $existingInvoiceItemCount = DB::table('invoice_items')
                ->whereIn('invoice_id', $invoiceIds)
                ->count();

            if ($existingInvoiceItemCount < count($invoiceIds)) {
                $existingInvoiceItemInvoiceIds = DB::table('invoice_items')
                    ->whereIn('invoice_id', $invoiceIds)
                    ->pluck('invoice_id')
                    ->flip();

                $rows = [];

                foreach ($invoiceIds as $index => $invoiceId) {
                    if (isset($existingInvoiceItemInvoiceIds[$invoiceId])) {
                        continue;
                    }

                    $serviceId = $serviceIds[
                        $index % count($serviceIds)
                    ];

                    $price = 150 + (($index % 10) * 25);

                    $rows[] = [
                        'invoice_id' => $invoiceId,
                        'service_id' => $serviceId,
                        'description' => 'Performance Test Service',
                        'quantity' => 1,
                        'price' => $price,
                    ];

                    if (count($rows) >= $chunkSize) {
                        DB::table('invoice_items')->insert($rows);

                        $rows = [];
                    }
                }

                if ($rows !== []) {
                    DB::table('invoice_items')->insert($rows);
                }
            }

            $this->command->info(
                'Tenant '.($tenantIndex + 1).' completed.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Clear tenant context
        |--------------------------------------------------------------------------
        */

        app(CurrentTenant::class)->clear();

        $this->command->info('');
        $this->command->info('==========================================');
        $this->command->info('Performance test data seeded successfully.');
        $this->command->info('==========================================');
    }
}
