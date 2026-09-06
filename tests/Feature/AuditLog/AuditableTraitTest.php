<?php

namespace Tests\Feature\AuditLog;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditableTraitTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);
    }

    private function actingAsUser(): User
    {
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_creating_a_model_while_authenticated_records_audit_log(): void
    {
        $user = $this->actingAsUser();

        $patient = Patient::factory()->create([
            'tenant_id' => $this->tenant->id,
            'full_name' => 'Sara Ahmed',
        ]);

        $log = AuditLog::query()
            ->where('auditable_type', $patient->getMorphClass())
            ->where('auditable_id', $patient->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($this->tenant->id, $log->tenant_id);
        $this->assertNull($log->old_values);
        $this->assertSame('Sara Ahmed', $log->new_values['full_name']);
    }

    public function test_creating_a_model_without_authenticated_user_does_not_record_audit_log(): void
    {
        // مفيش actingAsUser() هنا - محاكاة عملية console/system (زي seeder أو job).
        Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_updating_a_model_records_only_the_fields_that_actually_changed(): void
    {
        $patient = Patient::factory()->create([
            'tenant_id' => $this->tenant->id,
            'full_name' => 'Old Name',
            'phone' => '0100000000',
        ]);

        $this->actingAsUser();

        $patient->update([
            'full_name' => 'New Name',
            'phone' => '0100000000', // نفس القيمة القديمة - ما ينفعش يتسجل كتغيير
        ]);

        $log = AuditLog::query()
            ->where('auditable_id', $patient->id)
            ->where('action', 'updated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(['full_name' => 'New Name'], $log->new_values);
        $this->assertSame(['full_name' => 'Old Name'], $log->old_values);
    }

    public function test_updating_a_model_with_no_actual_changes_does_not_record_audit_log(): void
    {
        $patient = Patient::factory()->create([
            'tenant_id' => $this->tenant->id,
            'full_name' => 'Same Name',
        ]);

        $this->actingAsUser();

        $patient->update(['full_name' => 'Same Name']);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_touching_only_the_timestamp_does_not_record_audit_log(): void
    {
        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAsUser();

        $patient->touch();

        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_deleting_a_model_records_full_old_values_and_null_new_values(): void
    {
        $patient = Patient::factory()->create([
            'tenant_id' => $this->tenant->id,
            'full_name' => 'To Be Deleted',
        ]);

        $this->actingAsUser();

        $patientId = $patient->id;
        $patient->delete();

        $log = AuditLog::query()
            ->where('auditable_id', $patientId)
            ->where('action', 'deleted')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('To Be Deleted', $log->old_values['full_name']);
        $this->assertNull($log->new_values);
    }

    /**
     * NOTE: بيفترض إن AppointmentFactory موجودة وبتوفر defaults صالحة
     * (status, scheduled_at, duration_minutes) - لو مش كده ابعتلي محتواها.
     */
    public function test_morph_relationship_resolves_correct_model_for_different_auditable_types(): void
    {
        $user = $this->actingAsUser();

        $patient = Patient::factory()->create(['tenant_id' => $this->tenant->id]);

        $appointment = Appointment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'patient_id' => $patient->id,
            'doctor_id' => $user->id,
            'created_by' => $user->id,
        ]);

        $patientLog = AuditLog::query()
            ->where('auditable_id', $patient->id)
            ->where('action', 'created')
            ->first();

        $appointmentLog = AuditLog::query()
            ->where('auditable_id', $appointment->id)
            ->where('action', 'created')
            ->first();

        $this->assertNotNull($patientLog);
        $this->assertNotNull($appointmentLog);

        $this->assertInstanceOf(Patient::class, $patientLog->auditable);
        $this->assertTrue($patientLog->auditable->is($patient));

        $this->assertInstanceOf(Appointment::class, $appointmentLog->auditable);
        $this->assertTrue($appointmentLog->auditable->is($appointment));
    }
}
