<?php

namespace Tests\Feature\LabWorks;

use App\Enums\UserRole;
use App\Models\Lab;
use App\Models\LabWork;
use App\Models\LabWorkType;
use App\Models\Patient;
use App\Models\Tenant;
use App\Support\LabWorks\LabWorkTiming;
use App\Support\Roles\ClinicPermissionCatalog;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LabWorkTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_another_clinics_lab_work_is_not_visible(): void
    {
        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $work = $this->makeWork();

        $this->actingAsTenantUser();

        $this->getJson('/api/v1/lab-works/'.$work->id)->assertNotFound();

        $ids = collect($this->getJson('/api/v1/lab-works')->assertOk()->json('data.items'))->pluck('id');
        $this->assertFalse($ids->contains($work->id));
    }

    public function test_patient_and_lab_must_belong_to_the_current_clinic(): void
    {
        $this->actingAsTenantUser();
        $ownLab = Lab::factory()->create();

        $otherTenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($otherTenant->id);
        $otherPatient = Patient::factory()->create();
        app(CurrentTenant::class)->set($ownLab->tenant_id);

        $this->postJson('/api/v1/lab-works', [
            'patient_id' => $otherPatient->id,
            'lab_id' => $ownLab->id,
            'description' => 'Crown',
            'sent_on' => '2026-10-21',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id']);
    }

    public function test_expected_date_cannot_be_before_the_sent_date(): void
    {
        $this->actingAsTenantUser();
        $patient = Patient::factory()->create();
        $lab = Lab::factory()->create();

        $this->postJson('/api/v1/lab-works', [
            'patient_id' => $patient->id,
            'lab_id' => $lab->id,
            'description' => 'Bridge',
            'sent_on' => '2026-10-21',
            'expected_on' => '2026-10-20',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['expected_on']);
    }

    public function test_expected_date_defaults_from_the_lab_turnaround(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', LabWorkTiming::clinicTimezone()));
        $this->actingAsTenantUser();
        $patient = Patient::factory()->create();
        $lab = Lab::factory()->create(['default_turnaround_days' => 5]);
        $openEnded = Lab::factory()->create(['default_turnaround_days' => null]);

        $this->postJson('/api/v1/lab-works', [
            'patient_id' => $patient->id,
            'lab_id' => $lab->id,
            'description' => 'Crown',
        ])->assertCreated()
            ->assertJsonPath('data.sent_on', '2026-10-21')
            ->assertJsonPath('data.expected_on', '2026-10-26')
            ->assertJsonPath('data.status', 'at_lab');

        $this->postJson('/api/v1/lab-works', [
            'patient_id' => $patient->id,
            'lab_id' => $openEnded->id,
            'description' => 'Denture',
        ])->assertCreated()
            ->assertJsonPath('data.expected_on', '2026-10-21');
    }

    public function test_inactive_lab_cannot_be_used_for_a_new_case(): void
    {
        $this->actingAsTenantUser();
        $patient = Patient::factory()->create();
        $lab = Lab::factory()->inactive()->create();

        $this->postJson('/api/v1/lab-works', [
            'patient_id' => $patient->id,
            'lab_id' => $lab->id,
            'description' => 'Veneer',
            'sent_on' => '2026-10-21',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['lab_id']);
    }

    public function test_mark_received_sets_the_clinic_today_and_can_be_undone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 23:30:00', 'UTC'));
        $this->actingAsTenantUser();
        $work = $this->makeWork([
            'sent_on' => '2026-10-01',
            'expected_on' => '2026-10-20',
        ]);

        $this->patchJson('/api/v1/lab-works/'.$work->id.'/status', [
            'status' => 'received',
        ])->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.received_on', LabWorkTiming::todayDateString())
            ->assertJsonPath('data.timing.kind', 'late');

        $this->patchJson('/api/v1/lab-works/'.$work->id.'/status', [
            'status' => 'at_lab',
        ])->assertOk()
            ->assertJsonPath('data.status', 'at_lab')
            ->assertJsonPath('data.received_on', null);
    }

    public function test_received_case_cannot_be_cancelled_until_it_is_undone(): void
    {
        $this->actingAsTenantUser(null, UserRole::OWNER);
        $work = $this->makeWork(['status' => 'received', 'received_on' => '2026-10-21', 'sent_on' => '2026-10-01', 'expected_on' => '2026-10-21']);

        $this->patchJson('/api/v1/lab-works/'.$work->id.'/status', [
            'status' => 'cancelled',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);

        $this->assertSame('received', $work->refresh()->status->value);
    }

    public function test_cancelled_case_cannot_be_edited_or_reopened(): void
    {
        $this->actingAsTenantUser(null, UserRole::OWNER);
        $work = $this->makeWork();

        $this->patchJson('/api/v1/lab-works/'.$work->id.'/status', [
            'status' => 'cancelled',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->putJson('/api/v1/lab-works/'.$work->id, [
            'description' => 'Changed',
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/lab-works/'.$work->id.'/status', [
            'status' => 'at_lab',
        ])->assertUnprocessable();
    }

    public function test_summary_counts_overdue_and_monday_through_sunday(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-21 12:00:00', LabWorkTiming::clinicTimezone()));
        $this->actingAsTenantUser();

        $this->makeWork(['expected_on' => '2026-10-20', 'sent_on' => '2026-10-01']);
        $this->makeWork(['expected_on' => '2026-10-21', 'sent_on' => '2026-10-01']);
        $this->makeWork(['expected_on' => '2026-10-25', 'sent_on' => '2026-10-01']);
        $this->makeWork(['expected_on' => '2026-10-26', 'sent_on' => '2026-10-01']);
        $this->makeWork([
            'status' => 'received',
            'sent_on' => '2026-10-01',
            'expected_on' => '2026-10-10',
            'received_on' => '2026-10-10',
        ]);

        $this->getJson('/api/v1/lab-works/summary')->assertOk()
            ->assertJsonPath('data.at_lab', 4)
            ->assertJsonPath('data.overdue', 1)
            ->assertJsonPath('data.due_this_week', 2);

        $overdueIds = collect($this->getJson('/api/v1/lab-works?timing=overdue')->assertOk()->json('data.items'))->pluck('expected_on');
        $this->assertSame(['2026-10-20'], $overdueIds->all());

        $week = collect($this->getJson('/api/v1/lab-works?timing=due_this_week')->assertOk()->json('data.items'))->pluck('expected_on')->sort()->values();
        $this->assertSame(['2026-10-21', '2026-10-25'], $week->all());
    }

    public function test_doctor_cannot_cancel_until_the_owner_grants_it(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsTenantUser($tenant, UserRole::OWNER);
        $work = $this->makeWork();

        $doctor = $this->actingAsTenantUser($tenant, UserRole::DOCTOR);

        $this->patchJson('/api/v1/lab-works/'.$work->id.'/status', [
            'status' => 'cancelled',
        ])->assertForbidden();

        $this->actingAsTenantUser($tenant, UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['doctor'];
        $permissions['labWork:cancel'] = true;
        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => $permissions,
        ])->assertOk();

        Sanctum::actingAs($doctor);
        app(CurrentTenant::class)->set($tenant->id);

        $this->patchJson('/api/v1/lab-works/'.$work->id.'/status', [
            'status' => 'cancelled',
        ])->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_receptionist_can_manage_labs_and_a_doctor_cannot_until_granted(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $labId = $this->postJson('/api/v1/labs', [
            'name' => 'Cairo Ceramics',
            'phone' => '01000000000',
            'default_turnaround_days' => 4,
        ])->assertCreated()->json('data.id');

        $this->patchJson('/api/v1/labs/'.$labId.'/deactivate', [])->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->getJson('/api/v1/labs?status=inactive')->assertOk()
            ->assertJsonPath('data.items.0.id', $labId);

        $doctor = $this->actingAsTenantUser($tenant, UserRole::DOCTOR);
        $this->getJson('/api/v1/labs?status=all')->assertForbidden();
        $this->postJson('/api/v1/labs', [
            'name' => 'Another Lab',
            'phone' => '01000000001',
        ])->assertForbidden();

        $this->actingAsTenantUser($tenant, UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['doctor'];
        $permissions['lab:create'] = true;
        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => $permissions,
        ])->assertOk();

        Sanctum::actingAs($doctor);
        app(CurrentTenant::class)->set($tenant->id);

        $this->postJson('/api/v1/labs', [
            'name' => 'Another Lab',
            'phone' => '01000000001',
        ])->assertCreated();
    }

    public function test_a_lab_with_no_cases_can_be_deleted_and_one_with_cases_cannot(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingAsTenantUser($tenant, UserRole::RECEPTIONIST);

        $emptyId = $this->postJson('/api/v1/labs', [
            'name' => 'Unused Lab',
            'phone' => '01000000002',
        ])->assertCreated()->json('data.id');

        $this->getJson('/api/v1/labs/'.$emptyId)->assertOk()
            ->assertJsonPath('data.name', 'Unused Lab')
            ->assertJsonPath('data.phone', '01000000002');

        $this->deleteJson('/api/v1/labs/'.$emptyId)->assertOk();
        $this->getJson('/api/v1/labs/'.$emptyId)->assertNotFound();

        $used = Lab::factory()->create(['name' => 'Busy Lab', 'phone' => '01000000003']);
        LabWork::factory()->create([
            'patient_id' => Patient::factory()->create()->id,
            'lab_id' => $used->id,
            'sent_on' => '2026-10-01',
            'expected_on' => '2026-10-08',
        ]);

        $this->deleteJson('/api/v1/labs/'.$used->id)->assertStatus(422);
        $this->assertTrue($used->fresh()->is_active);

        $doctor = $this->actingAsTenantUser($tenant, UserRole::DOCTOR);
        $spare = Lab::factory()->create(['name' => 'Spare Lab', 'phone' => '01000000004']);
        $this->deleteJson('/api/v1/labs/'.$spare->id)->assertForbidden();

        $this->actingAsTenantUser($tenant, UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['doctor'];
        $permissions['lab:delete'] = true;
        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => $permissions,
        ])->assertOk();

        Sanctum::actingAs($doctor);
        app(CurrentTenant::class)->set($tenant->id);

        $this->deleteJson('/api/v1/labs/'.$spare->id)->assertOk();
    }

    public function test_a_clinic_can_save_work_names_in_arabic_or_english(): void
    {
        $this->actingAsTenantUser();

        $englishId = $this->postJson('/api/v1/lab-work-types', [
            'name' => '  Zirconia crown  ',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Zirconia crown')
            ->json('data.id');

        $this->postJson('/api/v1/lab-work-types', [
            'name' => 'zirconia   crown',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);

        $this->postJson('/api/v1/lab-work-types', [
            'name' => 'تاج  زركونيا',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'تاج زركونيا');

        $names = collect($this->getJson('/api/v1/lab-work-types')->assertOk()->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Zirconia crown'));
        $this->assertTrue($names->contains('تاج زركونيا'));

        $this->deleteJson('/api/v1/lab-work-types/'.$englishId)->assertOk();

        $remaining = collect($this->getJson('/api/v1/lab-work-types')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($remaining->contains($englishId));
        $this->assertTrue($remaining->contains(
            LabWorkType::query()->where('name', 'تاج زركونيا')->value('id')
        ));
    }

    public function test_lab_errors_follow_the_site_language(): void
    {
        $this->actingAsTenantUser();
        $patient = Patient::factory()->create();
        $lab = Lab::factory()->create();

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/lab-works', [
                'patient_id' => $patient->id,
                'lab_id' => $lab->id,
                'description' => '',
                'sent_on' => '2026-10-21',
            ])->assertUnprocessable()
            ->assertJsonPath('errors.description.0', 'حقل العمل مطلوب.');

        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/lab-works', [
                'patient_id' => $patient->id,
                'lab_id' => $lab->id,
                'description' => '',
                'sent_on' => '2026-10-21',
            ])->assertUnprocessable()
            ->assertJsonPath('errors.description.0', 'The work field is required.');

        $this->withHeader('Accept-Language', 'en')
            ->postJson('/api/v1/lab-work-types', ['name' => 'Zirconia crown'])
            ->assertCreated();

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/lab-work-types', ['name' => 'Zirconia crown'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'هذا العمل موجود في القائمة.');

        $this->withHeader('Accept-Language', 'ar')
            ->postJson('/api/v1/lab-works', [
                'patient_id' => $patient->id,
                'lab_id' => $lab->id,
                'description' => 'Crown',
                'sent_on' => '2026-10-21',
                'expected_on' => '2026-10-20',
            ])->assertUnprocessable()
            ->assertJsonPath('errors.expected_on.0', 'يجب أن يكون تاريخ الاستلام المتوقع تاريخاً بعد أو يساوي تاريخ الإرسال.');
    }

    public function test_another_clinic_cannot_see_or_remove_work_names(): void
    {
        $tenantB = Tenant::factory()->create();
        app(CurrentTenant::class)->set($tenantB->id);
        $type = LabWorkType::factory()->create(['name' => 'Secret work']);

        $this->actingAsTenantUser();

        $ids = collect($this->getJson('/api/v1/lab-work-types')->assertOk()->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($type->id));
        $this->deleteJson('/api/v1/lab-work-types/'.$type->id)->assertNotFound();
        $this->assertNotNull($type->fresh());
    }

    public function test_saving_a_work_name_follows_lab_work_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $doctor = $this->actingAsTenantUser($tenant, UserRole::DOCTOR);

        $this->postJson('/api/v1/lab-work-types', [
            'name' => 'Night guard repair',
        ])->assertCreated();

        $this->actingAsTenantUser($tenant, UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['doctor'];
        $permissions['labWork:create'] = false;
        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => $permissions,
        ])->assertOk();

        Sanctum::actingAs($doctor);
        app(CurrentTenant::class)->set($tenant->id);

        $this->getJson('/api/v1/lab-work-types')->assertOk();
        $this->postJson('/api/v1/lab-work-types', [
            'name' => 'Temporary crown',
        ])->assertForbidden();

        $existing = LabWorkType::query()->where('name', 'Night guard repair')->firstOrFail();
        $this->deleteJson('/api/v1/lab-work-types/'.$existing->id)->assertForbidden();

        $this->actingAsTenantUser($tenant, UserRole::OWNER);
        $permissions['labWork:view'] = false;
        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => $permissions,
        ])->assertOk();

        Sanctum::actingAs($doctor);
        app(CurrentTenant::class)->set($tenant->id);

        $this->getJson('/api/v1/lab-work-types')->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeWork(array $overrides = []): LabWork
    {
        $patient = Patient::factory()->create();
        $lab = Lab::factory()->create();

        return LabWork::factory()->create(array_merge([
            'patient_id' => $patient->id,
            'lab_id' => $lab->id,
            'sent_on' => '2026-10-01',
            'expected_on' => '2026-10-21',
        ], $overrides));
    }
}
