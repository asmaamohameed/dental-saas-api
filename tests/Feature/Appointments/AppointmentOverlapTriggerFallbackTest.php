<?php

namespace Tests\Feature\Appointments;

use App\Enums\AppointmentStatus;
use App\Enums\AppointmentType;
use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AppointmentOverlapTriggerFallbackTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $doctor;

    protected Patient $patient;

    protected bool $hadExclusionConstraint = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->set($this->tenant->id);

        $this->doctor = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => UserRole::DOCTOR,
        ]);

        $this->patient = Patient::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->hadExclusionConstraint = (bool) DB::selectOne("
            SELECT 1 FROM pg_constraint WHERE conname = 'no_overlapping_doctor_appointments'
        ");

        $this->prepareTriggerFallbackOnly();
    }

    protected function tearDown(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS check_appointment_overlap ON appointments');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_overlapping_doctor_appointments()');

        if ($this->hadExclusionConstraint) {
            try {
                DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
                DB::statement("
                    ALTER TABLE appointments
                    ADD CONSTRAINT no_overlapping_doctor_appointments
                    EXCLUDE USING gist (
                        doctor_id WITH =,
                        tsrange(scheduled_at, scheduled_at + (duration_minutes * interval '1 minute')) WITH &&
                    ) WHERE (status != 'cancelled')
                ");
            } catch (QueryException) {
                // Restore best-effort only; test DB may lack btree_gist.
            }
        }

        parent::tearDown();
    }

    protected function prepareTriggerFallbackOnly(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS no_overlapping_doctor_appointments');
        DB::unprepared('DROP TRIGGER IF EXISTS check_appointment_overlap ON appointments');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_overlapping_doctor_appointments()');

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_overlapping_doctor_appointments()
            RETURNS trigger AS \$\$
            BEGIN
                IF NEW.status = 'cancelled' OR NEW.doctor_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF TG_OP = 'UPDATE' THEN
                    IF NEW.doctor_id IS NOT DISTINCT FROM OLD.doctor_id
                       AND NEW.scheduled_at IS NOT DISTINCT FROM OLD.scheduled_at
                       AND NEW.duration_minutes IS NOT DISTINCT FROM OLD.duration_minutes
                       AND NEW.status IS NOT DISTINCT FROM OLD.status THEN
                        RETURN NEW;
                    END IF;
                END IF;

                PERFORM pg_advisory_xact_lock(hashtext(NEW.doctor_id::text));

                IF EXISTS (
                    SELECT 1 FROM appointments
                    WHERE doctor_id = NEW.doctor_id
                      AND status != 'cancelled'
                      AND id <> NEW.id
                      AND scheduled_at < (NEW.scheduled_at + (NEW.duration_minutes * interval '1 minute'))
                      AND (scheduled_at + (duration_minutes * interval '1 minute')) > NEW.scheduled_at
                ) THEN
                    RAISE EXCEPTION 'This doctor already has an appointment during this time slot.'
                        USING ERRCODE = '23P01';
                END IF;

                RETURN NEW;
            END;
            \$\$ LANGUAGE plpgsql;
        ");

        DB::unprepared('
            CREATE TRIGGER check_appointment_overlap
            BEFORE INSERT OR UPDATE ON appointments
            FOR EACH ROW EXECUTE PROCEDURE prevent_overlapping_doctor_appointments();
        ');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function appointmentRow(array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'created_by' => $this->doctor->id,
            'scheduled_at' => '2026-10-05 09:00:00',
            'duration_minutes' => 30,
            'status' => AppointmentStatus::SCHEDULED->value,
            'appointment_type' => AppointmentType::CONSULTATION->value,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    public function test_trigger_rejects_a_second_overlapping_insert_with_sqlstate_23p01(): void
    {
        DB::table('appointments')->insert($this->appointmentRow([
            'id' => (string) Str::uuid(),
            'scheduled_at' => '2026-10-05 09:00:00',
        ]));

        try {
            DB::table('appointments')->insert($this->appointmentRow([
                'id' => (string) Str::uuid(),
                'scheduled_at' => '2026-10-05 09:15:00',
            ]));
            $this->fail('Expected QueryException for overlapping appointment insert.');
        } catch (QueryException $e) {
            $this->assertSame('23P01', $e->errorInfo[0] ?? null);

            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            DB::beginTransaction();
        }
    }

    public function test_trigger_allows_overlapping_row_when_new_appointment_is_cancelled(): void
    {
        DB::table('appointments')->insert($this->appointmentRow([
            'id' => (string) Str::uuid(),
            'scheduled_at' => '2026-10-05 09:00:00',
        ]));

        DB::table('appointments')->insert($this->appointmentRow([
            'id' => (string) Str::uuid(),
            'scheduled_at' => '2026-10-05 09:00:00',
            'status' => AppointmentStatus::CANCELLED->value,
        ]));

        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_trigger_allows_back_to_back_appointments(): void
    {
        DB::table('appointments')->insert($this->appointmentRow([
            'id' => (string) Str::uuid(),
            'scheduled_at' => '2026-10-05 09:00:00',
            'duration_minutes' => 30,
        ]));

        DB::table('appointments')->insert($this->appointmentRow([
            'id' => (string) Str::uuid(),
            'scheduled_at' => '2026-10-05 09:30:00',
            'duration_minutes' => 30,
        ]));

        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_trigger_allows_notes_only_update_on_legacy_overlapping_rows(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS check_appointment_overlap ON appointments');

        $firstId = (string) Str::uuid();
        $secondId = (string) Str::uuid();

        DB::table('appointments')->insert($this->appointmentRow([
            'id' => $firstId,
            'scheduled_at' => '2026-10-05 09:00:00',
        ]));

        DB::table('appointments')->insert($this->appointmentRow([
            'id' => $secondId,
            'scheduled_at' => '2026-10-05 09:15:00',
        ]));

        DB::unprepared('
            CREATE TRIGGER check_appointment_overlap
            BEFORE INSERT OR UPDATE ON appointments
            FOR EACH ROW EXECUTE PROCEDURE prevent_overlapping_doctor_appointments();
        ');

        DB::table('appointments')->where('id', $secondId)->update([
            'notes' => 'Legacy overlap note edit only',
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('appointments', [
            'id' => $secondId,
            'notes' => 'Legacy overlap note edit only',
        ]);
    }
}
