<?php

namespace Database\Seeders;

use App\Support\Roles\ClinicMembershipSync;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LoadTestSeeder extends Seeder
{
    private const INSERT_CHUNK = 1_000;

    public function run(): void
    {
        if (! app()->environment(['local', 'staging'])) {
            $this->command->warn('LoadTestSeeder runs only in local/staging (not via DatabaseSeeder).');

            return;
        }

        $this->command->info('Starting load test data seeding...');

        $this->cleanup();

        /*
        |--------------------------------------------------------------------------
        | Configuration
        |--------------------------------------------------------------------------
        */

        $tenantCount = 1;
        $patientsPerTenant = 20_000;
        $appointmentsPerTenant = 60_000;
        $expensesPerTenant = 5_000;
        $passwordHash = Hash::make('password');

        for ($t = 1; $t <= $tenantCount; $t++) {
            $tenantStart = microtime(true);
            $now = Carbon::now();

            /*
            |------------------------------------------------------------------
            | Tenant + Subscription
            |------------------------------------------------------------------
            */

            $tenantId = Str::uuid()->toString();
            DB::table('tenants')->insert([
                'id' => $tenantId,
                'subdomain' => "loadtest-clinic-{$t}",
                'name' => "LoadTest Clinic {$t}",
                'status' => 'active',
            ]);
            app(CurrentTenant::class)->set($tenantId);

            DB::table('subscriptions')->insert([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'plan_type' => 'Premium',
                'start_date' => $now->copy()->subDays(10)->toDateString(),
                'end_date' => $now->copy()->addYear()->toDateString(),
                'status' => 'active',
            ]);

            /*
            |------------------------------------------------------------------
            | Users: 1 owner, 3-5 doctors, 1 assistant, 2 receptionists
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating staff users...");

            $phoneCounter = 0;
            $makeUser = function (string $email, string $name, string $role) use ($tenantId, $passwordHash, $t, &$phoneCounter): string {
                $id = Str::uuid()->toString();
                DB::table('users')->insert([
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'email' => $email,
                    'phone' => '+2011'.str_pad((string) (($t * 1000) + (++$phoneCounter)), 8, '0', STR_PAD_LEFT),
                    'password_hash' => $passwordHash,
                    'role' => $role,
                    'is_active' => true,
                ]);

                return $id;
            };

            $ownerId = $makeUser("owner{$t}@loadtest.com", "Owner {$t}", 'owner');
            $userIds = [$ownerId];

            $doctorCount = random_int(3, 5);
            $doctorIds = [];
            for ($d = 1; $d <= $doctorCount; $d++) {
                $id = $makeUser("doctor{$t}_{$d}@loadtest.com", "Dr. Doctor {$t}-{$d}", 'doctor');
                $doctorIds[] = $id;
                $userIds[] = $id;
            }

            $userIds[] = $makeUser("assistant{$t}_1@loadtest.com", "Assistant {$t}-1", 'assistant');
            $userIds[] = $makeUser("receptionist{$t}_1@loadtest.com", "Receptionist {$t}-1", 'receptionist');
            $userIds[] = $makeUser("receptionist{$t}_2@loadtest.com", "Receptionist {$t}-2", 'receptionist');

            $this->command->info("[Tenant {$t}] Staff done ({$doctorCount} doctors).");

            /*
            |------------------------------------------------------------------
            | Patients
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating {$patientsPerTenant} patients...");

            $patientRows = [];
            $patientIds = [];
            for ($n = 1; $n <= $patientsPerTenant; $n++) {
                $id = Str::uuid()->toString();
                $patientIds[] = $id;
                $patientRows[] = [
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'full_name' => "Patient {$t}-{$n}",
                    'phone' => '+200'.str_pad((string) $n, 9, '0', STR_PAD_LEFT),
                    'date_of_birth' => $now->copy()->subYears(20 + ($n % 50))->subDays($n % 365)->toDateString(),
                    'gender' => ($n % 2 === 0) ? 'male' : 'female',
                    'medical_history' => json_encode(['allergies' => [], 'notes' => 'Load test patient']),
                ];
            }
            $this->insertChunked('patients', $patientRows);
            unset($patientRows);

            $patientCount = count($patientIds);
            $this->command->info("[Tenant {$t}] Patients done: {$patientCount}");

            /*
            |------------------------------------------------------------------
            | Treatment templates: visit services + clinical treatments
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating treatment templates...");

            $templateDefs = [
                'exam' => ['Exam', 'فحص', 'visit', 'full_mouth', 200, 30],
                'consultation' => ['Consultation', 'استشارة', 'visit', 'full_mouth', 300, 30],
                'follow_up' => ['Follow-up', 'متابعة', 'visit', 'full_mouth', 150, 15],
                'filling' => ['Filling', 'حشوة', 'medical', 'tooth', 350, 45],
                'root_canal' => ['Root canal', 'علاج عصب', 'medical', 'tooth', 1500, 90],
                'crown' => ['Crown', 'تاج', 'medical', 'tooth', 2500, 60],
                'extraction' => ['Extraction', 'خلع', 'surgical', 'tooth', 500, 30],
            ];
            $tpl = [];
            $templateRows = [];
            foreach ($templateDefs as $key => [$nameEn, $nameAr, $category, $scope, $price, $duration]) {
                $id = Str::uuid()->toString();
                $tpl[$key] = ['id' => $id, 'name' => $nameEn, 'price' => $price, 'duration' => $duration];
                $templateRows[] = [
                    'id' => $id,
                    'tenant_id' => $tenantId,
                    'name_ar' => $nameAr,
                    'name_en' => $nameEn,
                    'category' => $category,
                    'scope' => $scope,
                    'description' => $nameEn,
                    'default_price' => $price,
                    'estimated_duration_minutes' => $duration,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            DB::table('treatment_templates')->insert($templateRows);

            $visitKeys = ['exam', 'consultation', 'follow_up'];
            $toothKeys = ['filling', 'root_canal', 'crown', 'extraction'];
            $fdi = [];
            foreach ([1, 2, 3, 4] as $quadrant) {
                for ($i = 1; $i <= 8; $i++) {
                    $fdi[] = (string) ($quadrant * 10 + $i);
                }
            }

            /*
            |------------------------------------------------------------------
            | Full visit lifecycle (all appointments in the past, Sun-Thu 9:00-16:30,
            | unique slot per doctor so the overlap constraint never fires)
            |   0-1  no_show          2-3  cancelled
            |   4-11 visit fee only   12-17 treatment (no plan)
            |   18   plan (2 treatments + visit line merged)   19 paid then refunded
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating {$appointmentsPerTenant} appointments with billing + treatments...");

            $workingDaysCache = [];
            $cursor = $now->copy();
            $neededDays = (int) ceil($appointmentsPerTenant / $doctorCount / 16) + 30;
            while (count($workingDaysCache) < $neededDays) {
                if (! in_array($cursor->dayOfWeek, [Carbon::FRIDAY, Carbon::SATURDAY])) {
                    $workingDaysCache[] = $cursor->copy();
                }
                $cursor->subDay();
            }

            $doctorSlot = array_fill_keys($doctorIds, 0);
            $planRows = $appointmentRows = $treatmentRows = $teethRows = [];
            $sessionRows = $invoiceRows = $itemRows = $paymentRows = $refundRows = [];

            for ($num = 0; $num < $appointmentsPerTenant; $num++) {
                $apptId = Str::uuid()->toString();
                $patientId = $patientIds[$num % $patientCount];
                $doctorId = $doctorIds[$num % $doctorCount];

                $slotIndex = $doctorSlot[$doctorId]++;
                $slotInDay = $slotIndex % 16;
                $scheduledAt = $workingDaysCache[intdiv($slotIndex, 16)]
                    ->copy()
                    ->setHour(9 + intdiv($slotInDay, 2))
                    ->setMinute(($slotInDay % 2) * 30)
                    ->setSecond(0);
                $startedAt = $scheduledAt->copy()->addMinutes(5);
                $completedAt = $scheduledAt->copy()->addMinutes(30);

                $kind = $num % 20;
                $round = intdiv($num, 20);
                $visitKey = $visitKeys[$round % 3];
                $visit = $tpl[$visitKey];

                $appt = [
                    'id' => $apptId,
                    'tenant_id' => $tenantId,
                    'patient_id' => $patientId,
                    'doctor_id' => $doctorId,
                    'created_by' => $userIds[array_rand($userIds)],
                    'scheduled_at' => $scheduledAt,
                    'duration_minutes' => $visit['duration'],
                    'status' => 'completed',
                    'appointment_type' => $visitKey === 'follow_up' ? 'follow_up' : 'consultation',
                    'service_id' => $visit['id'],
                    'fee' => $visit['price'],
                    'treatment_plan_id' => null,
                    'checked_in_at' => $scheduledAt,
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                    'notes' => 'Load test appointment',
                    'created_at' => $scheduledAt,
                    'updated_at' => $completedAt,
                ];

                if ($kind < 4) {
                    // a) no_show / e) cancelled before check-in: appointment only
                    $appt['status'] = $kind < 2 ? 'no_show' : 'cancelled';
                    $appt['checked_in_at'] = $appt['started_at'] = $appt['completed_at'] = null;
                } elseif ($kind < 12 || $kind === 19) {
                    // b) visit fee only (and f: same, then fully paid + refunded)
                    $invoiceId = Str::uuid()->toString();
                    $fee = (float) $visit['price'];
                    [$invStatus, $paid] = $kind === 19 ? ['refunded', $fee] : $this->payState($round, $fee);

                    $invoiceRows[] = [
                        'id' => $invoiceId, 'tenant_id' => $tenantId, 'patient_id' => $patientId,
                        'appointment_id' => $apptId, 'treatment_plan_id' => null,
                        'created_by' => $doctorId, 'total_amount' => $fee, 'status' => $invStatus,
                        'created_at' => $scheduledAt, 'updated_at' => $completedAt,
                    ];
                    $itemRows[] = [
                        'id' => Str::uuid()->toString(), 'tenant_id' => $tenantId, 'invoice_id' => $invoiceId,
                        'patient_treatment_id' => null, 'patient_treatment_tooth_id' => null,
                        'appointment_id' => $apptId, 'description' => $visit['name'],
                        'price' => $fee, 'quantity' => 1,
                        'created_at' => $scheduledAt, 'updated_at' => $scheduledAt,
                    ];
                    if ($paid > 0) {
                        $paymentRows[] = $this->paymentRow($tenantId, $invoiceId, $paid, $completedAt, $userIds);
                    }
                    if ($kind === 19) {
                        $refundRows[] = [
                            'id' => Str::uuid()->toString(), 'tenant_id' => $tenantId, 'invoice_id' => $invoiceId,
                            'amount' => $fee, 'type' => 'full', 'invoice_item_id' => null,
                            'reason_code' => 'other', 'reason_note' => 'Load test refund',
                            'method' => 'cash', 'refunded_by' => $ownerId,
                            'refunded_at' => $completedAt, 'created_at' => $completedAt, 'updated_at' => $completedAt,
                        ];
                    }
                } elseif ($kind < 18) {
                    // c) doctor adds a tooth treatment, no plan, no visit fee
                    $appt['appointment_type'] = 'treatment_visit';
                    $appt['service_id'] = null;
                    $appt['fee'] = null;
                    $appt['duration_minutes'] = 30;

                    $clinical = ($round % 10) < 7 ? 'completed' : 'in_progress';
                    $g = $this->treatmentGraph(
                        $tenantId, $patientId, $doctorId, null, $apptId,
                        $tpl[$toothKeys[$num % 4]], 1 + ($round % 3), $clinical, $fdi, $num, $scheduledAt
                    );
                    $treatmentRows[] = $g['treatment'];
                    array_push($teethRows, ...$g['teeth']);
                    $sessionRows[] = $g['session'];

                    [$invStatus, $paid] = $clinical === 'completed' ? $this->payState($round, $g['total']) : ['unpaid', 0.0];
                    $treatmentRows[array_key_last($treatmentRows)]['financial_status'] = $invStatus;

                    $invoiceId = Str::uuid()->toString();
                    $invoiceRows[] = [
                        'id' => $invoiceId, 'tenant_id' => $tenantId, 'patient_id' => $patientId,
                        'appointment_id' => null, 'treatment_plan_id' => null,
                        'created_by' => $doctorId, 'total_amount' => $g['total'], 'status' => $invStatus,
                        'created_at' => $scheduledAt, 'updated_at' => $completedAt,
                    ];
                    $itemRows[] = $this->treatmentItemRow($tenantId, $invoiceId, $g, $scheduledAt);
                    if ($paid > 0) {
                        $paymentRows[] = $this->paymentRow($tenantId, $invoiceId, $paid, $completedAt, $userIds);
                    }
                } else {
                    // d) plan with 2 treatments + visit line merged into the plan invoice
                    $planId = Str::uuid()->toString();
                    $planRows[] = [
                        'id' => $planId, 'tenant_id' => $tenantId, 'patient_id' => $patientId,
                        'title' => "Load test plan {$num}", 'status' => 'active',
                        'created_at' => $scheduledAt, 'updated_at' => $scheduledAt,
                    ];
                    $appt['treatment_plan_id'] = $planId;

                    $fee = (float) $visit['price'];
                    $invoiceId = Str::uuid()->toString();
                    $planItems = [[
                        'id' => Str::uuid()->toString(), 'tenant_id' => $tenantId, 'invoice_id' => $invoiceId,
                        'patient_treatment_id' => null, 'patient_treatment_tooth_id' => null,
                        'appointment_id' => $apptId, 'description' => $visit['name'],
                        'price' => $fee, 'quantity' => 1,
                        'created_at' => $scheduledAt, 'updated_at' => $scheduledAt,
                    ]];
                    $grandTotal = $fee;
                    $graphs = [];

                    for ($k = 0; $k < 2; $k++) {
                        $g = $this->treatmentGraph(
                            $tenantId, $patientId, $doctorId, $planId, $apptId,
                            $tpl[$toothKeys[($num + $k) % 4]], 1 + $k, 'completed', $fdi, $num + $k, $scheduledAt
                        );
                        $graphs[] = $g;
                        $grandTotal += $g['total'];
                        $planItems[] = $this->treatmentItemRow($tenantId, $invoiceId, $g, $scheduledAt);
                    }

                    [$invStatus, $paid] = $this->payState($round, $grandTotal);
                    foreach ($graphs as $g) {
                        $g['treatment']['financial_status'] = $invStatus;
                        $treatmentRows[] = $g['treatment'];
                        array_push($teethRows, ...$g['teeth']);
                        $sessionRows[] = $g['session'];
                    }

                    $invoiceRows[] = [
                        'id' => $invoiceId, 'tenant_id' => $tenantId, 'patient_id' => $patientId,
                        'appointment_id' => null, 'treatment_plan_id' => $planId,
                        'created_by' => $doctorId, 'total_amount' => $grandTotal, 'status' => $invStatus,
                        'created_at' => $scheduledAt, 'updated_at' => $completedAt,
                    ];
                    array_push($itemRows, ...$planItems);
                    if ($paid > 0) {
                        $paymentRows[] = $this->paymentRow($tenantId, $invoiceId, $paid, $completedAt, $userIds);
                    }
                }

                $appointmentRows[] = $appt;
            }
            unset($workingDaysCache);

            // FK order: templates (done) -> plans -> appointments -> treatments -> teeth
            //           -> sessions -> invoices -> items -> payments -> refunds
            $this->insertChunked('treatment_plans', $planRows);
            $this->insertChunked('appointments', $appointmentRows);
            $this->insertChunked('patient_treatments', $treatmentRows);
            $this->insertChunked('patient_treatment_teeth', $teethRows);
            $this->insertChunked('treatment_sessions', $sessionRows);
            $this->insertChunked('invoices', $invoiceRows);
            $this->insertChunked('invoice_items', $itemRows);
            $this->insertChunked('payments', $paymentRows);
            $this->insertChunked('refunds', $refundRows);

            $this->command->info(sprintf(
                '[Tenant %d] Appointments %d, treatments %d, invoices %d, payments %d, refunds %d',
                $t, count($appointmentRows), count($treatmentRows), count($invoiceRows), count($paymentRows), count($refundRows)
            ));
            unset($planRows, $appointmentRows, $treatmentRows, $teethRows, $sessionRows, $invoiceRows, $itemRows, $paymentRows, $refundRows);

            /*
            |------------------------------------------------------------------
            | Expenses (spread over the last 12 months)
            |------------------------------------------------------------------
            */

            $this->command->info("[Tenant {$t}] Creating {$expensesPerTenant} expenses...");

            $categories = ['electricity', 'internet', 'rent', 'water', 'salary', 'lab', 'other'];
            $expenseRows = [];
            for ($i = 0; $i < $expensesPerTenant; $i++) {
                $date = $now->copy()->subDays($i % 365);
                $expenseRows[] = [
                    'id' => Str::uuid()->toString(),
                    'tenant_id' => $tenantId,
                    'category' => $categories[$i % count($categories)],
                    'title' => "Load test expense {$i}",
                    'amount' => 50 + (($i % 40) * 25),
                    'expense_date' => $date->toDateString(),
                    'created_by' => $ownerId,
                    'notes' => null,
                    'created_at' => $date,
                    'updated_at' => $date,
                ];
            }
            $this->insertChunked('expenses', $expenseRows);
            unset($expenseRows);

            $elapsed = round(microtime(true) - $tenantStart, 1);
            $this->command->info("[Tenant {$t}] Complete in {$elapsed}s");
        }

        if (DB::getDriverName() === 'pgsql') {
            $this->command->info('Running ANALYZE...');
            DB::statement('ANALYZE');
        }

        ClinicMembershipSync::backfillMissing();

        app(CurrentTenant::class)->clear();

        $this->command->info('Load test data seeded successfully.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunked(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    /** @return array{0: string, 1: float} [invoice status, paid amount] */
    private function payState(int $i, float $total): array
    {
        return match ($i % 3) {
            0 => ['paid', $total],
            1 => ['partial', round($total / 2, 2)],
            default => ['unpaid', 0.0],
        };
    }

    private function paymentRow(string $tenantId, string $invoiceId, float $amount, Carbon $at, array $userIds): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'invoice_id' => $invoiceId,
            'amount' => $amount,
            'deduct_amount' => 0,
            'paid_at' => $at,
            'method' => ['cash', 'transfer', 'card'][random_int(0, 2)],
            'received_by' => $userIds[array_rand($userIds)],
            'notes' => null,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    private function treatmentItemRow(string $tenantId, string $invoiceId, array $g, Carbon $at): array
    {
        return [
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'invoice_id' => $invoiceId,
            'patient_treatment_id' => $g['treatment']['id'],
            'patient_treatment_tooth_id' => null,
            'appointment_id' => null,
            'description' => $g['label'],
            'price' => $g['total'],
            'quantity' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ];
    }

    /**
     * One tooth-scope treatment: treatment + teeth + one completed session linked to the appointment.
     * agreed_price is the PER-TOOTH price (total = agreed_price x teeth), same as the app.
     */
    private function treatmentGraph(
        string $tenantId, string $patientId, string $doctorId, ?string $planId, string $appointmentId,
        array $tpl, int $toothCount, string $clinical, array $fdi, int $seed, Carbon $at
    ): array {
        $treatmentId = Str::uuid()->toString();
        $done = $clinical === 'completed';
        $teeth = [];
        $numbers = [];
        for ($k = 0; $k < $toothCount; $k++) {
            $number = $fdi[($seed + $k) % 32];
            $numbers[] = $number;
            $teeth[] = [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'patient_treatment_id' => $treatmentId,
                'tooth_number' => $number,
                'status' => $done ? 'done' : 'in_progress',
                'price' => $tpl['price'],
                'completed_at' => $done ? $at : null,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        return [
            'total' => (float) ($tpl['price'] * $toothCount),
            'label' => $tpl['name'].' ('.implode(', ', $numbers).')',
            'teeth' => $teeth,
            'treatment' => [
                'id' => $treatmentId,
                'tenant_id' => $tenantId,
                'patient_id' => $patientId,
                'treatment_plan_id' => $planId,
                'treatment_template_id' => $tpl['id'],
                'scope' => 'tooth',
                'agreed_price' => $tpl['price'],
                'clinical_status' => $clinical,
                'financial_status' => 'unpaid',
                'treatment_type' => 'original',
                'priority' => 'routine',
                'dentist_id' => $doctorId,
                'created_by' => $doctorId,
                'notes' => 'Load test treatment',
                'started_at' => $at,
                'completed_at' => $done ? $at : null,
                'created_at' => $at,
                'updated_at' => $at,
            ],
            'session' => [
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenantId,
                'patient_treatment_id' => $treatmentId,
                'appointment_id' => $appointmentId,
                'dentist_id' => $doctorId,
                'session_date' => $at,
                'status' => 'completed',
                'notes' => 'Load test session',
                'tooth_numbers' => json_encode($numbers),
                'created_at' => $at,
                'updated_at' => $at,
            ],
        ];
    }

    /**
     * Destructive cleanup of every loadtest-* tenant and its data.
     */
    private function cleanup(): void
    {
        $this->command->info('Cleaning up previous load test data...');

        $tenantIds = DB::table('tenants')
            ->where('subdomain', 'like', 'loadtest-%')
            ->pluck('id')
            ->toArray();

        if (empty($tenantIds)) {
            return;
        }

        $memberIds = DB::table('clinic_members')->whereIn('clinic_id', $tenantIds)->pluck('id')->toArray();
        if (! empty($memberIds)) {
            DB::table('clinic_member_role')->whereIn('clinic_member_id', $memberIds)->delete();
            DB::table('clinic_members')->whereIn('id', $memberIds)->delete();
        }

        // Order matters (children first). appointments.service_id and
        // appointments.treatment_plan_id are RESTRICT, so appointments must go
        // before treatment_plans and treatment_templates.
        $tables = [
            'refunds',
            'invoice_items',
            'payments',
            'invoices',
            'treatment_sessions',
            'patient_treatment_teeth',
            'patient_treatments',
            'tooth_records',
            'xray_attachments',
            'expenses',
            'lab_works',
            'labs',
            'lab_work_types',
            'inventory_transactions',
            'inventory_items',
            'audit_logs',
            'appointments',
            'treatment_plans',
            'treatment_templates',
            'patients',
            'users',
            'subscriptions',
        ];

        foreach ($tables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tenant_id')) {
                DB::table($table)->whereIn('tenant_id', $tenantIds)->delete();
            }
        }

        DB::table('tenants')->whereIn('id', $tenantIds)->delete();

        $this->command->info('Cleanup complete: removed '.count($tenantIds).' previous tenants.');
    }
}