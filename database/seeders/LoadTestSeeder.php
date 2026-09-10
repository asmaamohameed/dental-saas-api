<?php

namespace Database\Seeders;

use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoadTestSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Starting load test data seeding...');

        /*
        |--------------------------------------------------------------------------
        | Destructive cleanup of any existing loadtest-* tenant data
        |--------------------------------------------------------------------------
        | invoice_items has tenant_id (added via later migration), but we also
        | clean via invoice_id to be safe. All other child tables use tenant_id.
        */

        $this->command->info('Cleaning up previous load test data...');
        $loadTestTenantIds = DB::table('tenants')
            ->where('subdomain', 'like', 'loadtest-%')
            ->pluck('id')
            ->toArray();

        if (! empty($loadTestTenantIds)) {
            // invoice_items: delete via invoice_id (join through invoices)
            $loadTestInvoiceIds = DB::table('invoices')
                ->whereIn('tenant_id', $loadTestTenantIds)
                ->pluck('id')
                ->toArray();
            if (! empty($loadTestInvoiceIds)) {
                foreach (array_chunk($loadTestInvoiceIds, 5000) as $chunk) {
                    DB::table('invoice_items')->whereIn('invoice_id', $chunk)->delete();
                }
            }

            // All other child tables have tenant_id directly
            $childTables = [
                'payments', 'invoices', 'appointments',
                'tooth_records', 'xray_attachments', 'patients', 'users',
                'services', 'subscriptions',
            ];
            foreach ($childTables as $table) {
                DB::table($table)->whereIn('tenant_id', $loadTestTenantIds)->delete();
            }
            DB::table('tenants')->whereIn('id', $loadTestTenantIds)->delete();
            $this->command->info('Cleanup complete: removed '.count($loadTestTenantIds).' previous tenants.');
        }

        /*
        |--------------------------------------------------------------------------
        | Configuration
        |--------------------------------------------------------------------------
        */

        $tenantCount = 10; // Set to 1 for testing, 10 for full run
        $patientsPerTenant = 5_000;
        $appointmentsPerTenant = 20_000;
        $servicesPerTenant = 20; // 19 regular + 1 "Other"
        $chunkSize = 2_000;
        $passwordHash = Hash::make('password');

        // Appointment status distribution: 60% completed, 20% scheduled, 10% cancelled, 10% no_show
        $statusDistribution = array_merge(
            array_fill(0, 6, 'completed'),
            array_fill(0, 2, 'scheduled'),
            array_fill(0, 1, 'cancelled'),
            array_fill(0, 1, 'no_show'),
        );

        // Valid tooth conditions per migration
        $toothConditions = ['healthy', 'decayed', 'filled', 'missing', 'crown', 'root_canal', 'needs_extraction', 'impacted'];

        // Valid treatment statuses per migration
        $treatmentStatuses = ['planned', 'in_progress', 'completed'];

        for ($t = 1; $t <= $tenantCount; $t++) {
            $tenantStart = microtime(true);
            $subdomain = "loadtest-clinic-{$t}";

            /*
            |------------------------------------------------------------------
            | Tenant + Subscription
            | Using firstOrCreate-style: check existence, only generate UUID
            | for new rows to avoid overwriting valid IDs on re-runs.
            |------------------------------------------------------------------
            */

            $existingTenant = DB::table('tenants')->where('subdomain', $subdomain)->first();
            if ($existingTenant) {
                $tenantId = $existingTenant->id;
                DB::table('tenants')->where('id', $tenantId)->update([
                    'name' => "LoadTest Clinic {$t}",
                    'status' => 'active',
                ]);
            } else {
                $tenantId = Str::uuid()->toString();
                DB::table('tenants')->insert([
                    'id' => $tenantId,
                    'subdomain' => $subdomain,
                    'name' => "LoadTest Clinic {$t}",
                    'status' => 'active',
                ]);
            }
            app(CurrentTenant::class)->set($tenantId);

            // Subscription: firstOrCreate-style
            $existingSub = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();
            if (! $existingSub) {
                DB::table('subscriptions')->insert([
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'plan_type' => 'Premium',
                    'start_date' => Carbon::now()->subDays(10)->toDateString(),
                    'end_date' => Carbon::now()->addYear()->toDateString(),
                    'status' => 'active',
                ]);
            }

            /*
            |------------------------------------------------------------------
            | Users (5-15 staff: 1 owner, 3-5 doctors, rest receptionists)
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating staff users...");

            $staffCount = random_int(5, 15);
            $doctorCount = random_int(3, min(5, $staffCount - 2)); // at least 1 owner + 1 receptionist
            $receptionistCount = $staffCount - 1 - $doctorCount; // 1 is the owner

            $userIds = [];
            $doctorIds = [];

            // Owner
            $ownerEmail = "owner{$t}@loadtest.com";
            $existing = DB::table('users')->where('email', $ownerEmail)->first();
            if (! $existing) {
                $ownerId = Str::uuid()->toString();
                DB::table('users')->insert([
                    'id' => $ownerId,
                    'tenant_id' => $tenantId,
                    'name' => "Owner {$t}",
                    'email' => $ownerEmail,
                    'phone' => '+200'.random_int(1000000, 9999999),
                    'password_hash' => $passwordHash,
                    'role' => 'owner',
                    'is_active' => true,
                ]);
            } else {
                $ownerId = $existing->id;
            }
            $userIds[] = $ownerId;

            // Doctors
            for ($d = 1; $d <= $doctorCount; $d++) {
                $doctorEmail = "doctor{$t}_{$d}@loadtest.com";
                $existing = DB::table('users')->where('email', $doctorEmail)->first();
                if (! $existing) {
                    $docId = Str::uuid()->toString();
                    DB::table('users')->insert([
                        'id' => $docId,
                        'tenant_id' => $tenantId,
                        'name' => "Dr. Doctor {$t}-{$d}",
                        'email' => $doctorEmail,
                        'phone' => '+200'.random_int(1000000, 9999999),
                        'password_hash' => $passwordHash,
                        'role' => 'doctor',
                        'is_active' => true,
                    ]);
                } else {
                    $docId = $existing->id;
                }
                $userIds[] = $docId;
                $doctorIds[] = $docId;
            }

            // Receptionists
            for ($r = 1; $r <= $receptionistCount; $r++) {
                $recEmail = "receptionist{$t}_{$r}@loadtest.com";
                $existing = DB::table('users')->where('email', $recEmail)->first();
                if (! $existing) {
                    $recId = Str::uuid()->toString();
                    DB::table('users')->insert([
                        'id' => $recId,
                        'tenant_id' => $tenantId,
                        'name' => "Receptionist {$t}-{$r}",
                        'email' => $recEmail,
                        'phone' => '+200'.random_int(1000000, 9999999),
                        'password_hash' => $passwordHash,
                        'role' => 'receptionist',
                        'is_active' => true,
                    ]);
                } else {
                    $recId = $existing->id;
                }
                $userIds[] = $recId;
            }

            $this->command->info("[Tenant {$t}] Created {$staffCount} staff ({$doctorCount} doctors).");

            /*
            |------------------------------------------------------------------
            | Services (19 regular + "Other" with is_other flag)
            | firstOrCreate-style: only generate UUID for new services
            |------------------------------------------------------------------
            */

            $serviceIds = [];
            for ($s = 1; $s <= $servicesPerTenant - 1; $s++) {
                $nameAr = "خدمة أسنان {$s} - عيادة {$t}";
                $existingService = DB::table('services')
                    ->where('tenant_id', $tenantId)
                    ->where('name_ar', $nameAr)
                    ->first();
                if (! $existingService) {
                    $svcId = Str::uuid()->toString();
                    DB::table('services')->insert([
                        'id' => $svcId,
                        'tenant_id' => $tenantId,
                        'name_ar' => $nameAr,
                        'name_en' => "Dental Service {$s}",
                        'default_price' => 100 + ($s * 25),
                        'is_active' => true,
                        'is_other' => false,
                    ]);
                    $serviceIds[] = $svcId;
                } else {
                    $serviceIds[] = $existingService->id;
                }
            }
            // "Other" service
            $existingOther = DB::table('services')
                ->where('tenant_id', $tenantId)
                ->where('name_ar', 'أخرى')
                ->first();
            if (! $existingOther) {
                $otherId = Str::uuid()->toString();
                DB::table('services')->insert([
                    'id' => $otherId,
                    'tenant_id' => $tenantId,
                    'name_ar' => 'أخرى',
                    'name_en' => 'Other',
                    'default_price' => 0,
                    'is_active' => true,
                    'is_other' => true,
                ]);
                $serviceIds[] = $otherId;
            } else {
                $serviceIds[] = $existingOther->id;
            }

            /*
            |------------------------------------------------------------------
            | Patients (5,000 per tenant, chunked)
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating {$patientsPerTenant} patients...");

            $existingPatientCount = DB::table('patients')->where('tenant_id', $tenantId)->count();
            $remainingPatients = max(0, $patientsPerTenant - $existingPatientCount);
            for ($offset = 0; $offset < $remainingPatients; $offset += $chunkSize) {
                $batchSize = min($chunkSize, $remainingPatients - $offset);
                $batch = [];
                for ($p = 1; $p <= $batchSize; $p++) {
                    $num = $existingPatientCount + $offset + $p;
                    $batch[] = [
                        'id' => Str::uuid()->toString(),
                        'tenant_id' => $tenantId,
                        'full_name' => "Patient {$t}-{$num}",
                        'phone' => '+200'.str_pad((string) $num, 9, '0', STR_PAD_LEFT),
                        'date_of_birth' => Carbon::now()
                            ->subYears(20 + ($num % 50))
                            ->subDays($num % 365)
                            ->toDateString(),
                        'gender' => ($num % 2 === 0) ? 'male' : 'female',
                        'medical_history' => json_encode([
                            'allergies' => [],
                            'notes' => 'Load test patient',
                        ]),
                    ];
                }
                DB::table('patients')->insert($batch);
            }
            $patientIds = DB::table('patients')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->pluck('id')
                ->toArray();

            $this->command->info("[Tenant {$t}] Patients done: ".count($patientIds));

            /*
            |------------------------------------------------------------------
            | Appointments (20,000 per tenant, Mon-Thu 9-5, rotating doctors,
            | status distribution: 60% completed, 20% scheduled, 10% cancelled, 10% no_show)
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating {$appointmentsPerTenant} appointments...");

            $existingApptCount = DB::table('appointments')->where('tenant_id', $tenantId)->count();
            $remainingAppts = max(0, $appointmentsPerTenant - $existingApptCount);
            $doctorIdCount = count($doctorIds);

            for ($offset = 0; $offset < $remainingAppts; $offset += $chunkSize) {
                $batchSize = min($chunkSize, $remainingAppts - $offset);
                $batch = [];
                for ($a = 0; $a < $batchSize; $a++) {
                    $num = $existingApptCount + $offset + $a;
                    $patientId = $patientIds[$num % count($patientIds)];

                    // Rotate doctors round-robin
                    $doctorId = $doctorIds[$num % $doctorIdCount];

                    // Working days: Mon-Thu (skip Fri=5, Sat=6)
                    $daysBack = $num % 730; // ~2 years
                    $scheduledAt = Carbon::now()->subDays($daysBack);
                    // Skip Friday (5) and Saturday (6)
                    while (in_array($scheduledAt->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY])) {
                        $scheduledAt->subDay();
                    }
                    $scheduledAt->setHour(9 + ($num % 8)) // 9-16 (9AM to 4PM, last slot)
                        ->setMinute(($num % 2) * 30)       // :00 or :30
                        ->setSecond(0);

                    // Status distribution
                    $status = $statusDistribution[$num % 10];

                    $batch[] = [
                        'id' => Str::uuid()->toString(),
                        'tenant_id' => $tenantId,
                        'patient_id' => $patientId,
                        'doctor_id' => $doctorId,
                        'created_by' => $userIds[array_rand($userIds)],
                        'scheduled_at' => $scheduledAt,
                        'duration_minutes' => 30,
                        'status' => $status,
                        'notes' => 'Load test appointment',
                    ];
                }
                DB::table('appointments')->insert($batch);
            }

            $this->command->info("[Tenant {$t}] Appointments done.");

            /*
            |------------------------------------------------------------------
            | Build appointment lookup: id -> {patient_id, doctor_id, status}
            | Only completed appointments get invoices; all get tooth records.
            |------------------------------------------------------------------
            */

            $allAppointments = DB::table('appointments')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->select(['id', 'patient_id', 'doctor_id', 'status'])
                ->get();

            $completedAppointments = $allAppointments->where('status', 'completed')->values();

            $this->command->info("[Tenant {$t}] Completed appointments for invoicing: ".$completedAppointments->count());

            /*
            |------------------------------------------------------------------
            | Tooth Records (2-4 per appointment, with patient_id derived
            | from appointment, recorded_by from doctor, valid conditions)
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating tooth records...");

            $toothBatch = [];
            foreach ($allAppointments as $appt) {
                $recordCount = random_int(2, 4);
                for ($tr = 0; $tr < $recordCount; $tr++) {
                    $toothBatch[] = [
                        'id' => Str::uuid()->toString(),
                        'tenant_id' => $tenantId,
                        'patient_id' => $appt->patient_id,
                        'appointment_id' => $appt->id,
                        'recorded_by' => $appt->doctor_id,
                        'tooth_number' => (string) random_int(11, 48),
                        'condition' => $toothConditions[array_rand($toothConditions)],
                        'treatment_status' => $treatmentStatuses[array_rand($treatmentStatuses)],
                        'notes' => 'Load test tooth record',
                    ];
                    if (count($toothBatch) >= $chunkSize) {
                        DB::table('tooth_records')->insert($toothBatch);
                        $toothBatch = [];
                    }
                }
            }
            if (! empty($toothBatch)) {
                DB::table('tooth_records')->insert($toothBatch);
            }

            $this->command->info("[Tenant {$t}] Tooth records done.");

            /*
            |------------------------------------------------------------------
            | X-ray Attachments (5,000 per tenant)
            | Schema: file_url, file_type, uploaded_by, appointment_id
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating X-ray attachments...");

            $xrayBatch = [];
            for ($x = 0; $x < 5000; $x++) {
                $appt = $allAppointments[$x % count($allAppointments)];
                $xrayBatch[] = [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'patient_id' => $appt->patient_id,
                    'appointment_id' => $appt->id,
                    'file_url' => "https://storage.loadtest.local/xrays/tenant-{$t}/xray_{$x}.png",
                    'file_type' => 'image/png',
                    'uploaded_by' => $appt->doctor_id,
                ];
                if (count($xrayBatch) >= $chunkSize) {
                    DB::table('xray_attachments')->insert($xrayBatch);
                    $xrayBatch = [];
                }
            }
            if (! empty($xrayBatch)) {
                DB::table('xray_attachments')->insert($xrayBatch);
            }

            $this->command->info("[Tenant {$t}] X-ray attachments done.");

            /*
            |------------------------------------------------------------------
            | Invoices (only from completed appointments)
            | CRITICAL: patient_id is derived FROM the appointment, not independently
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating invoices from completed appointments...");

            $invoiceBatch = [];
            $completedCount = $completedAppointments->count();
            for ($inv = 0; $inv < $completedCount; $inv++) {
                $appt = $completedAppointments[$inv];
                $price = 150 + (($inv % 10) * 25);
                $invoiceBatch[] = [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'patient_id' => $appt->patient_id, // derived from appointment
                    'appointment_id' => $appt->id,
                    'created_by' => $appt->doctor_id,
                    'total_amount' => $price,
                    'status' => ($inv % 3 === 0) ? 'paid' : (($inv % 3 === 1) ? 'unpaid' : 'partial'),
                ];
                if (count($invoiceBatch) >= $chunkSize) {
                    DB::table('invoices')->insert($invoiceBatch);
                    $invoiceBatch = [];
                }
            }
            if (! empty($invoiceBatch)) {
                DB::table('invoices')->insert($invoiceBatch);
            }

            $invoiceIds = DB::table('invoices')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->pluck('id')
                ->toArray();

            $this->command->info("[Tenant {$t}] Invoices done: ".count($invoiceIds));

            /*
            |------------------------------------------------------------------
            | Invoice Items (1-4 per invoice, chunked)
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating invoice items...");

            $itemBatch = [];
            foreach ($invoiceIds as $idx => $invoiceId) {
                $itemCount = random_int(1, 4);
                for ($c = 0; $c < $itemCount; $c++) {
                    $serviceId = $serviceIds[$c % count($serviceIds)];
                    $price = 150 + (($idx % 10) * 25);
                    $itemBatch[] = [
                        'id' => Str::uuid()->toString(),
                        'tenant_id' => $tenantId,
                        'invoice_id' => $invoiceId,
                        'service_id' => $serviceId,
                        'description' => 'Load test service',
                        'quantity' => 1,
                        'price' => $price,
                    ];
                    if (count($itemBatch) >= $chunkSize) {
                        DB::table('invoice_items')->insert($itemBatch);
                        $itemBatch = [];
                    }
                }
            }
            if (! empty($itemBatch)) {
                DB::table('invoice_items')->insert($itemBatch);
            }

            $this->command->info("[Tenant {$t}] Invoice items done.");

            /*
            |------------------------------------------------------------------
            | Payments (0-3 per invoice, BATCHED in chunks of 2000)
            | Schema: tenant_id, invoice_id, amount, paid_at, method,
            |         received_by (nullable), notes (nullable)
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating payments...");

            $paymentBatch = [];
            foreach ($invoiceIds as $invoiceId) {
                $payCount = random_int(0, 3);
                for ($p = 0; $p < $payCount; $p++) {
                    $paymentBatch[] = [
                        'id' => Str::uuid()->toString(),
                        'tenant_id' => $tenantId,
                        'invoice_id' => $invoiceId,
                        'amount' => random_int(10, 100),
                        'paid_at' => Carbon::now()->subDays(random_int(0, 30)),
                        'method' => ['cash', 'transfer', 'card'][random_int(0, 2)],
                        'received_by' => $userIds[array_rand($userIds)],
                        'notes' => null,
                    ];
                    if (count($paymentBatch) >= $chunkSize) {
                        DB::table('payments')->insert($paymentBatch);
                        $paymentBatch = [];
                    }
                }
            }
            if (! empty($paymentBatch)) {
                DB::table('payments')->insert($paymentBatch);
            }

            $this->command->info("[Tenant {$t}] Payments done.");

            $elapsed = round(microtime(true) - $tenantStart, 1);
            $this->command->info("[Tenant {$t}] ✅ Complete in {$elapsed}s");
        }

        /*
        |--------------------------------------------------------------------------
        | Clear tenant context
        |--------------------------------------------------------------------------
        */

        app(CurrentTenant::class)->clear();

        $this->command->info('');
        $this->command->info('==========================================');
        $this->command->info('Load test data seeded successfully.');
        $this->command->info('==========================================');
    }
}
