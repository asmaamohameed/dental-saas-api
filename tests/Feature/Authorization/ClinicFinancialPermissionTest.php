<?php

namespace Tests\Feature\Authorization;

use App\Enums\AuditAction;
use App\Enums\TreatmentScope;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ClinicRolePermission;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\TreatmentTemplate;
use App\Models\User;
use App\Support\Roles\ClinicPermissionCatalog;
use App\Support\Roles\ClinicPermissionGate;
use App\Support\Tenancy\CurrentTenant;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClinicFinancialPermissionTest extends TestCase
{
    private Tenant $tenant;

    /** @var list<string> */
    private array $dashboardPaths = [
        '/api/v1/dashboard/financial-analytics',
        '/api/v1/dashboard/income-expense',
        '/api/v1/dashboard/patients',
        '/api/v1/dashboard/cashflow',
        '/api/v1/dashboard/expense-breakdown',
        '/api/v1/dashboard/invoice-status',
        '/api/v1/dashboard/refund-breakdown',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    public function test_new_keys_match_the_baseline_and_the_admin_matrix(): void
    {
        $expected = [
            'dashboard:financials' => ['owner' => true, 'doctor' => false, 'assistant' => false, 'receptionist' => true],
            'expense:view' => ['owner' => true, 'doctor' => false, 'assistant' => false, 'receptionist' => true],
            'expense:create' => ['owner' => true, 'doctor' => false, 'assistant' => false, 'receptionist' => true],
            'treatment:view' => ['owner' => true, 'doctor' => true, 'assistant' => true, 'receptionist' => true],
        ];

        foreach ($expected as $key => $roles) {
            foreach ($roles as $role => $allowed) {
                $this->assertSame($allowed, ClinicPermissionCatalog::baseline()[$role][$key], $role.' baseline '.$key);
                $this->assertSame($allowed, ClinicPermissionCatalog::matrix()[$role][$key], $role.' matrix '.$key);
            }
        }
    }

    public function test_dashboard_routes_follow_dashboard_financials(): void
    {
        foreach ($this->dashboardPaths as $path) {
            $this->assertRoleStatuses('GET', $path, $this->financialStatuses());
        }
    }

    public function test_expense_reads_and_creates_follow_expense_permissions(): void
    {
        $this->assertRoleStatuses('GET', '/api/v1/expenses', $this->financialStatuses());
        $this->assertRoleStatuses('POST', '/api/v1/expenses', [
            UserRole::OWNER->value => 201,
            UserRole::RECEPTIONIST->value => 201,
            UserRole::DOCTOR->value => 403,
            UserRole::ASSISTANT->value => 403,
        ], [
            'category' => 'rent',
            'amount' => 25,
        ]);
    }

    public function test_treatment_template_reads_follow_treatment_view(): void
    {
        $template = $this->template();

        foreach ([UserRole::OWNER, UserRole::DOCTOR, UserRole::ASSISTANT, UserRole::RECEPTIONIST] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/treatment-templates')->assertOk();
            $this->getJson("/api/v1/treatment-templates/{$template->id}")->assertOk();
        }
    }

    public function test_patient_treatment_and_plan_reads_follow_patient_view(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->template();
        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['21'],
        ])->assertCreated()->json('data.id');

        foreach ([UserRole::OWNER, UserRole::DOCTOR, UserRole::ASSISTANT, UserRole::RECEPTIONIST] as $role) {
            $this->actingAsRole($role);
            $this->getJson('/api/v1/patient-treatments')->assertOk();
            $this->getJson("/api/v1/patient-treatments/{$treatmentId}")->assertOk();
            $this->getJson("/api/v1/patients/{$patient->id}/treatment-plans")->assertOk();
        }
    }

    public function test_owner_can_grant_dashboard_financials_to_a_doctor(): void
    {
        $doctor = $this->actingAsRole(UserRole::DOCTOR);
        $this->getJson('/api/v1/dashboard/financial-analytics')->assertForbidden();

        $owner = $this->actingAsRole(UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['doctor'];
        $permissions['dashboard:financials'] = true;

        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => $permissions,
        ])->assertOk()
            ->assertJsonPath('data.roles.0.permissions.dashboard:financials', true);

        Sanctum::actingAs($doctor, ['*']);
        $this->getJson('/api/v1/dashboard/financial-analytics')->assertOk();

        $log = AuditLog::query()
            ->where('action', AuditAction::CLINIC_ROLE_PERMISSIONS_CHANGED)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($owner->id, $log->user_id);
        $this->assertSame($this->tenant->id, $log->auditable_id);
        $this->assertSame('doctor', $log->old_values['role']);
        $this->assertFalse($log->old_values['permissions']['dashboard:financials']);
        $this->assertSame('doctor', $log->new_values['role']);
        $this->assertTrue($log->new_values['permissions']['dashboard:financials']);
    }

    public function test_owner_can_revoke_expense_view_from_a_receptionist(): void
    {
        $receptionist = $this->actingAsRole(UserRole::RECEPTIONIST);
        $this->getJson('/api/v1/expenses')->assertOk();

        $this->actingAsRole(UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['receptionist'];
        $permissions['expense:view'] = false;

        $this->putJson('/api/v1/role-permissions/receptionist', [
            'permissions' => $permissions,
        ])->assertOk();

        Sanctum::actingAs($receptionist, ['*']);
        $this->getJson('/api/v1/expenses')->assertForbidden();
    }

    public function test_revoking_treatment_view_blocks_reads_only(): void
    {
        $template = $this->template();
        $assistant = $this->actingAsRole(UserRole::ASSISTANT);
        $this->getJson('/api/v1/treatment-templates')->assertOk();
        $this->getJson("/api/v1/treatment-templates/{$template->id}")->assertOk();

        $this->actingAsRole(UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['assistant'];
        $permissions['treatment:view'] = false;
        $this->putJson('/api/v1/role-permissions/assistant', [
            'permissions' => $permissions,
        ])->assertOk();

        Sanctum::actingAs($assistant, ['*']);
        $this->getJson('/api/v1/treatment-templates')->assertForbidden();
        $this->getJson("/api/v1/treatment-templates/{$template->id}")->assertForbidden();
        $this->postJson('/api/v1/treatment-templates', [
            'name_ar' => 'خدمة '.uniqid(),
            'name_en' => 'Still creatable '.uniqid(),
            'category' => 'medical',
            'default_price' => 40,
            'estimated_duration_minutes' => 20,
        ])->assertCreated();
    }

    public function test_revoking_patient_view_blocks_treatment_reads_only(): void
    {
        $this->actingAsRole(UserRole::OWNER);
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);
        $template = $this->template();
        $treatmentId = $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['21'],
        ])->assertCreated()->json('data.id');

        $permissions = ClinicPermissionCatalog::baseline()['assistant'];
        $permissions['patient:view'] = false;
        $this->putJson('/api/v1/role-permissions/assistant', [
            'permissions' => $permissions,
        ])->assertOk();

        $this->actingAsRole(UserRole::ASSISTANT);
        $this->getJson('/api/v1/patient-treatments')->assertForbidden();
        $this->getJson("/api/v1/patient-treatments/{$treatmentId}")->assertForbidden();
        $this->getJson("/api/v1/patients/{$patient->id}/treatment-plans")->assertForbidden();
        $this->postJson('/api/v1/patient-treatments', [
            'patient_id' => $patient->id,
            'treatment_template_id' => $template->id,
            'tooth_numbers' => ['11'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('appointment_id');
    }

    public function test_saved_permission_rows_missing_new_keys_fall_back_to_baseline(): void
    {
        ClinicRolePermission::query()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'doctor',
            'permissions' => ['staff:view' => true],
        ]);
        ClinicRolePermission::query()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'receptionist',
            'permissions' => ['patient:view' => false],
        ]);

        $doctor = User::factory()->doctor()->create(['tenant_id' => $this->tenant->id]);
        $receptionist = User::factory()->receptionist()->create(['tenant_id' => $this->tenant->id]);
        $gate = app(ClinicPermissionGate::class);

        $doctorFlags = $gate->forUser($doctor);
        $this->assertTrue($doctorFlags['staff:view']);
        $this->assertFalse($doctorFlags['dashboard:financials']);
        $this->assertFalse($doctorFlags['expense:view']);
        $this->assertFalse($doctorFlags['expense:create']);
        $this->assertTrue($doctorFlags['treatment:view']);

        $receptionistFlags = $gate->forUser($receptionist);
        $this->assertFalse($receptionistFlags['patient:view']);
        $this->assertTrue($receptionistFlags['dashboard:financials']);
        $this->assertTrue($receptionistFlags['expense:view']);
        $this->assertTrue($receptionistFlags['expense:create']);
        $this->assertTrue($receptionistFlags['treatment:view']);

        $stored = ClinicRolePermission::query()->where('role', 'doctor')->first();
        $this->assertNotNull($stored);
        $this->assertArrayNotHasKey('dashboard:financials', $stored->permissions);
        $this->assertArrayNotHasKey('expense:view', $stored->permissions);
        $this->assertArrayNotHasKey('expense:create', $stored->permissions);

        $this->actingAsRole(UserRole::OWNER);
        $this->getJson('/api/v1/role-permissions')
            ->assertOk()
            ->assertJsonPath('data.roles.0.permissions.dashboard:financials', false)
            ->assertJsonPath('data.roles.0.permissions.staff:view', true)
            ->assertJsonPath('data.roles.2.permissions.expense:view', true)
            ->assertJsonPath('data.roles.2.permissions.patient:view', false);
    }

    public function test_owner_cannot_change_another_tenants_role_permissions(): void
    {
        $other = Tenant::factory()->create();
        $otherBaseline = ClinicPermissionCatalog::baseline()['doctor'];
        ClinicRolePermission::query()->create([
            'tenant_id' => $other->id,
            'role' => 'doctor',
            'permissions' => $otherBaseline,
        ]);

        $this->actingAsRole(UserRole::OWNER);
        $permissions = ClinicPermissionCatalog::baseline()['doctor'];
        $permissions['dashboard:financials'] = true;

        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => $permissions,
        ])->assertOk();

        $otherRow = ClinicRolePermission::query()
            ->where('tenant_id', $other->id)
            ->where('role', 'doctor')
            ->first();

        $this->assertNotNull($otherRow);
        $this->assertFalse($otherRow->permissions['dashboard:financials']);
        $this->assertDatabaseMissing('audit_logs', [
            'tenant_id' => $other->id,
            'action' => AuditAction::CLINIC_ROLE_PERMISSIONS_CHANGED->value,
        ]);

        $ownRow = ClinicRolePermission::query()
            ->where('tenant_id', $this->tenant->id)
            ->where('role', 'doctor')
            ->first();

        $this->assertNotNull($ownRow);
        $this->assertTrue($ownRow->permissions['dashboard:financials']);
    }

    public function test_unknown_permission_key_is_rejected(): void
    {
        $this->actingAsRole(UserRole::OWNER);

        $this->putJson('/api/v1/role-permissions/doctor', [
            'permissions' => ['not-a-permission' => true],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('permissions');

        $this->assertDatabaseMissing('clinic_role_permissions', [
            'tenant_id' => $this->tenant->id,
            'role' => 'doctor',
        ]);
    }

    /**
     * @param  array<string, int>  $expectedByRole
     * @param  array<string, mixed>  $payload
     */
    private function assertRoleStatuses(string $method, string $uri, array $expectedByRole, array $payload = []): void
    {
        foreach ($expectedByRole as $role => $status) {
            $this->actingAsRole(UserRole::from($role));
            $response = $this->json($method, $uri, $payload);
            $this->assertSame($status, $response->status(), $role.' '.$method.' '.$uri);
        }
    }

    /**
     * @return array<string, int>
     */
    private function financialStatuses(): array
    {
        return [
            UserRole::OWNER->value => 200,
            UserRole::RECEPTIONIST->value => 200,
            UserRole::DOCTOR->value => 403,
            UserRole::ASSISTANT->value => 403,
        ];
    }

    private function actingAsRole(UserRole $role): User
    {
        $user = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => $role,
        ]);

        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    private function template(): TreatmentTemplate
    {
        return TreatmentTemplate::query()->create([
            'name_ar' => 'علاج',
            'name_en' => 'Clinical '.uniqid(),
            'category' => 'medical',
            'scope' => TreatmentScope::TOOTH,
            'default_price' => 100,
            'estimated_duration_minutes' => 30,
            'is_active' => true,
        ]);
    }
}
