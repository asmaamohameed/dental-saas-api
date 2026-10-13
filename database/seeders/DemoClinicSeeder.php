<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\InventoryTransactionType;
use App\Enums\PatientTreatmentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\TreatmentCategory;
use App\Enums\TreatmentScope;
use App\Enums\TreatmentSessionStatus;
use App\Enums\TreatmentToothStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\InventoryItem;
use App\Models\Patient;
use App\Models\PatientTreatment;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TreatmentSession;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Services\DefaultTreatmentServicesProvisioner;
use App\Services\InventoryTransactionService;
use App\Services\InvoiceService;
use App\Services\PatientTreatmentService;
use App\Services\PaymentService;
use App\Support\Roles\ClinicMembershipSync;
use App\Support\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class DemoClinicSeeder extends Seeder
{
    public const SUBDOMAIN = 'demo-clinic';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            return;
        }

        $plainPassword = $this->resolveDemoPassword();
        $tenant = Tenant::query()->firstOrCreate(
            ['subdomain' => self::SUBDOMAIN],
            [
                'name' => 'عيادة النيل للأسنان',
                'locale' => 'ar',
                'status' => 'active',
            ]
        );

        app(CurrentTenant::class)->set($tenant->id);

        Subscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan_type' => 'Premium',
                'start_date' => Carbon::now()->subMonth()->toDateString(),
                'end_date' => Carbon::now()->addYear()->toDateString(),
                'status' => SubscriptionStatus::ACTIVE,
            ]
        );

        $passwordHash = Hash::make($plainPassword);
        $workingDays = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'];

        $owner = $this->staffUser('owner@demo-clinic.test', 'أحمد المنشاوي', UserRole::OWNER, $passwordHash);
        $doctor1 = $this->staffUser('doctor1@demo-clinic.test', 'د. سارة محمود', UserRole::DOCTOR, $passwordHash, $workingDays);
        $doctor2 = $this->staffUser('doctor2@demo-clinic.test', 'د. كريم عادل', UserRole::DOCTOR, $passwordHash, $workingDays);
        $assistant = $this->staffUser('assistant@demo-clinic.test', 'مريم حسن', UserRole::ASSISTANT, $passwordHash);
        $receptionist = $this->staffUser('receptionist@demo-clinic.test', 'نور الدين فتحي', UserRole::RECEPTIONIST, $passwordHash);

        ClinicMembershipSync::backfillMissing();

        $templates = $this->seedTreatmentTemplates();
        $patients = $this->seedPatients();

        /** @var PatientTreatmentService $treatmentService */
        $treatmentService = app(PatientTreatmentService::class);
        /** @var InvoiceService $invoiceService */
        $invoiceService = app(InvoiceService::class);
        /** @var PaymentService $paymentService */
        $paymentService = app(PaymentService::class);

        $this->seedTreatmentsAndInvoices(
            $treatmentService,
            $invoiceService,
            $paymentService,
            $templates,
            $patients,
            $doctor1,
            $owner,
            $receptionist
        );

        $this->seedAppointments($patients, $doctor1, $doctor2, $receptionist);
        $this->seedInventory($owner);

        app(CurrentTenant::class)->clear();

        $this->command->info('Demo clinic seeded (subdomain: '.self::SUBDOMAIN.').');
        $this->command->info('Staff logins: owner@demo-clinic.test, doctor1@demo-clinic.test, doctor2@demo-clinic.test, assistant@demo-clinic.test, receptionist@demo-clinic.test (password: from DEMO_PASSWORD)');
    }

    private function resolveDemoPassword(): string
    {
        $password = env('DEMO_PASSWORD');

        if (is_string($password) && $password !== '') {
            return $password;
        }

        if (app()->environment(['local', 'testing'])) {
            return 'password';
        }

        throw new RuntimeException('Set DEMO_PASSWORD before seeding the demo clinic in staging.');
    }

    private function staffUser(
        string $email,
        string $name,
        UserRole $role,
        string $passwordHash,
        ?array $workingDays = null
    ): User {
        $attributes = [
            'name' => $name,
            'phone' => '+2010'.substr(md5($email), 0, 8),
            'password_hash' => $passwordHash,
            'role' => $role,
            'is_active' => true,
        ];

        if ($workingDays !== null) {
            $attributes['working_days'] = $workingDays;
        }

        return User::query()->updateOrCreate(
            ['email' => $email],
            $attributes
        );
    }

    /**
     * @return array<string, TreatmentTemplate>
     */
    private function seedTreatmentTemplates(): array
    {
        $provisioned = app(DefaultTreatmentServicesProvisioner::class)->provision();

        $demoExtras = [
            'quadrant_ortho' => [
                'name_ar' => 'تقويم الربع العلوي',
                'name_en' => 'Upper Quadrant Orthodontics',
                'category' => TreatmentCategory::ORTHODONTIC,
                'scope' => TreatmentScope::QUADRANT,
                'default_price' => 8000,
                'estimated_duration_minutes' => 120,
            ],
            'upper_denture' => [
                'name_ar' => 'طقم أسنان علوي',
                'name_en' => 'Upper Denture',
                'category' => TreatmentCategory::OTHER,
                'scope' => TreatmentScope::ARCH,
                'default_price' => 5000,
                'estimated_duration_minutes' => 120,
            ],
        ];

        $templates = [
            'filling' => $provisioned['filling'],
            'rct' => $provisioned['root_canal'],
            'scaling' => $provisioned['cleaning'],
        ];

        foreach ($demoExtras as $key => $data) {
            $templates[$key] = TreatmentTemplate::query()->firstOrCreate(
                ['name_en' => $data['name_en']],
                array_merge($data, [
                    'description' => $data['name_en'],
                    'is_active' => true,
                ])
            );
        }

        $templates['filling']->update(['default_price' => 350]);
        $templates['rct']->update(['default_price' => 1500]);
        $templates['scaling']->update(['default_price' => 400]);

        return $templates;
    }

    /**
     * @return list<Patient>
     */
    private function seedPatients(): array
    {
        $names = [
            ['full_name' => 'محمد عبد الرحمن', 'gender' => 'male'],
            ['full_name' => 'فاطمة الزهراء', 'gender' => 'female'],
            ['full_name' => 'يوسف خليل', 'gender' => 'male'],
            ['full_name' => 'مريم سامي', 'gender' => 'female'],
            ['full_name' => 'عمر حسين', 'gender' => 'male'],
            ['full_name' => 'ليلى إبراهيم', 'gender' => 'female'],
            ['full_name' => 'خالد مصطفى', 'gender' => 'male'],
            ['full_name' => 'هبة أشرف', 'gender' => 'female'],
            ['full_name' => 'تامر جمال', 'gender' => 'male'],
            ['full_name' => 'دينا محمود', 'gender' => 'female'],
        ];

        $patients = [];
        foreach ($names as $index => $row) {
            $phone = '+2015'.str_pad((string) (1000000 + $index), 7, '0', STR_PAD_LEFT);
            $patients[] = Patient::query()->firstOrCreate(
                ['phone' => $phone],
                [
                    'full_name' => $row['full_name'],
                    'gender' => $row['gender'],
                    'date_of_birth' => Carbon::now()->subYears(25 + $index)->toDateString(),
                    'address' => 'القاهرة، مصر',
                ]
            );
        }

        return $patients;
    }

    /**
     * @param  array<string, TreatmentTemplate>  $templates
     * @param  list<Patient>  $patients
     */
    private function seedTreatmentsAndInvoices(
        PatientTreatmentService $treatmentService,
        InvoiceService $invoiceService,
        PaymentService $paymentService,
        array $templates,
        array $patients,
        User $doctor,
        User $owner,
        User $receptionist
    ): void {
        $demoTag = '[demo-clinic]';

        if (PatientTreatment::query()->where('notes', 'like', $demoTag.'%')->exists()) {
            $this->command->info('Demo treatments already present; skipping treatment/invoice seed block.');

            return;
        }

        $planned = $treatmentService->createFromTemplate([
            'patient_id' => $patients[0]->id,
            'treatment_template_id' => $templates['filling']->id,
            'tooth_numbers' => ['16'],
            'notes' => $demoTag.' planned filling',
        ], $doctor);

        $inProgress = $treatmentService->createFromTemplate([
            'patient_id' => $patients[1]->id,
            'treatment_template_id' => $templates['rct']->id,
            'tooth_numbers' => ['26', '36'],
            'agreed_price' => 1500,
            'notes' => $demoTag.' in-progress rct',
        ], $doctor);

        $inProgress->teeth()->where('tooth_number', '26')->update([
            'status' => TreatmentToothStatus::DONE,
            'completed_at' => now()->subDay(),
        ]);
        $inProgress->teeth()->where('tooth_number', '36')->update([
            'status' => TreatmentToothStatus::IN_PROGRESS,
        ]);
        $treatmentService->recomputeClinicalStatus($inProgress->fresh(['teeth', 'sessions']), $owner->id);

        $completed = $treatmentService->createFromTemplate([
            'patient_id' => $patients[2]->id,
            'treatment_template_id' => $templates['filling']->id,
            'tooth_numbers' => ['11', '21'],
            'notes' => $demoTag.' completed filling',
        ], $doctor);
        foreach ($completed->teeth as $tooth) {
            $tooth->update([
                'status' => TreatmentToothStatus::DONE,
                'completed_at' => now()->subDays(3),
            ]);
        }
        $treatmentService->recomputeClinicalStatus($completed->fresh(['teeth', 'sessions']), $owner->id);

        $cancelled = $treatmentService->createFromTemplate([
            'patient_id' => $patients[3]->id,
            'treatment_template_id' => $templates['scaling']->id,
            'notes' => $demoTag.' cancelled scaling',
        ], $doctor);
        $treatmentService->updateClinicalStatus($cancelled, [
            'clinical_status' => PatientTreatmentStatus::CANCELLED,
            'cancellation_reason' => 'Patient rescheduled abroad',
        ], $owner->id);

        $unpaidTreatment = $treatmentService->createFromTemplate([
            'patient_id' => $patients[4]->id,
            'treatment_template_id' => $templates['filling']->id,
            'tooth_numbers' => ['46'],
            'notes' => $demoTag.' invoice unpaid',
        ], $doctor);

        $partialTreatment = $treatmentService->createFromTemplate([
            'patient_id' => $patients[5]->id,
            'treatment_template_id' => $templates['filling']->id,
            'tooth_numbers' => ['36'],
            'notes' => $demoTag.' invoice partial',
        ], $doctor);

        $paidTreatment = $treatmentService->createFromTemplate([
            'patient_id' => $patients[6]->id,
            'treatment_template_id' => $templates['filling']->id,
            'tooth_numbers' => ['31'],
            'notes' => $demoTag.' invoice paid',
        ], $doctor);

        foreach ([$planned, $inProgress, $completed, $unpaidTreatment, $partialTreatment, $paidTreatment] as $treatment) {
            $fresh = $treatment->fresh(['teeth', 'template', 'invoiceItems']);
            if ($fresh->invoiceItems->isNotEmpty()) {
                $invoiceService->syncTreatmentInvoiceItems($fresh);
            }
        }

        $partialInvoice = $partialTreatment->fresh(['invoiceItems.invoice'])->invoiceItems->first()?->invoice;
        $paidInvoice = $paidTreatment->fresh(['invoiceItems.invoice'])->invoiceItems->first()?->invoice;

        if ($partialInvoice !== null) {
            $partialTotal = (float) $partialInvoice->total_amount;
            $paymentService->create($partialInvoice, [
                'amount' => round($partialTotal / 2, 2),
                'method' => 'cash',
            ], $receptionist->id);
        }

        if ($paidInvoice !== null) {
            $paymentService->create($paidInvoice, [
                'amount' => (float) $paidInvoice->total_amount,
                'method' => 'cash',
            ], $owner->id);
        }

        TreatmentSession::query()->firstOrCreate(
            [
                'patient_treatment_id' => $inProgress->id,
                'session_date' => Carbon::today()->setHour(10),
            ],
            [
                'dentist_id' => $doctor->id,
                'status' => TreatmentSessionStatus::COMPLETED,
                'tooth_numbers' => ['36'],
                'notes' => 'Canal preparation visit',
            ]
        );

        unset($planned, $cancelled);
    }

    /**
     * @param  list<Patient>  $patients
     */
    private function seedAppointments(array $patients, User $doctor1, User $doctor2, User $receptionist): void
    {
        $today = Carbon::today();

        Appointment::query()->firstOrCreate(
            [
                'patient_id' => $patients[7]->id,
                'doctor_id' => $doctor1->id,
                'scheduled_at' => $today->copy()->setHour(9)->setMinute(0),
            ],
            [
                'created_by' => $receptionist->id,
                'booked_doctor_id' => $doctor1->id,
                'duration_minutes' => 30,
                'status' => AppointmentStatus::CHECKED_IN,
                'appointment_type' => AppointmentType::TREATMENT_VISIT,
                'checked_in_at' => $today->copy()->setHour(9),
                'notes' => '[demo-clinic] checked in today',
            ]
        );

        Appointment::query()->firstOrCreate(
            [
                'patient_id' => $patients[8]->id,
                'doctor_id' => $doctor2->id,
                'scheduled_at' => $today->copy()->setHour(10)->setMinute(30),
            ],
            [
                'created_by' => $receptionist->id,
                'booked_doctor_id' => $doctor2->id,
                'duration_minutes' => 45,
                'status' => AppointmentStatus::IN_PROGRESS,
                'appointment_type' => AppointmentType::TREATMENT_VISIT,
                'checked_in_at' => $today->copy()->setHour(10)->setMinute(15),
                'started_at' => $today->copy()->setHour(10)->setMinute(35),
                'notes' => '[demo-clinic] in progress today',
            ]
        );

        Appointment::query()->firstOrCreate(
            [
                'patient_id' => $patients[9]->id,
                'doctor_id' => $doctor1->id,
                'scheduled_at' => $today->copy()->addDays(2)->setHour(11),
            ],
            [
                'created_by' => $receptionist->id,
                'booked_doctor_id' => $doctor1->id,
                'duration_minutes' => 30,
                'status' => AppointmentStatus::SCHEDULED,
                'appointment_type' => AppointmentType::CONSULTATION,
                'notes' => '[demo-clinic] upcoming',
            ]
        );

        Appointment::query()->firstOrCreate(
            [
                'patient_id' => $patients[0]->id,
                'doctor_id' => $doctor1->id,
                'scheduled_at' => $today->copy()->subDay()->setHour(14),
            ],
            [
                'created_by' => $receptionist->id,
                'booked_doctor_id' => $doctor1->id,
                'duration_minutes' => 45,
                'status' => AppointmentStatus::COMPLETED,
                'appointment_type' => AppointmentType::TREATMENT_VISIT,
                'checked_in_at' => $today->copy()->subDay()->setHour(14),
                'started_at' => $today->copy()->subDay()->setHour(14)->addMinutes(5),
                'completed_at' => $today->copy()->subDay()->setHour(15),
                'notes' => '[demo-clinic] completed yesterday',
            ]
        );
    }

    private function seedInventory(User $owner): void
    {
        /** @var InventoryTransactionService $inventoryTx */
        $inventoryTx = app(InventoryTransactionService::class);

        $items = [
            ['name' => 'Composite resin A2', 'unit' => 'syringe', 'qty' => '40.00'],
            ['name' => 'Disposable gloves (M)', 'unit' => 'box', 'qty' => '25.00'],
        ];

        foreach ($items as $row) {
            $item = InventoryItem::query()->firstOrCreate(
                ['name' => $row['name']],
                [
                    'unit' => $row['unit'],
                    'minimum_threshold' => 5,
                    'is_active' => true,
                ]
            );

            if ($item->transactions()->exists()) {
                continue;
            }

            $inventoryTx->create($item, [
                'type' => InventoryTransactionType::IN->value,
                'quantity' => $row['qty'],
                'reason' => 'Opening stock (demo seed)',
            ], $owner->id);
        }
    }
}
