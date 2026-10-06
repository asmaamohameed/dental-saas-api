<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $constraintExists = DB::selectOne("
            SELECT 1 FROM pg_constraint WHERE conname = 'no_overlapping_doctor_appointments'
        ");

        if ($constraintExists) {
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS no_overlapping_doctor_appointments');
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("
                ALTER TABLE appointments
                ADD CONSTRAINT no_overlapping_doctor_appointments
                EXCLUDE USING gist (
                    doctor_id WITH =,
                    tsrange(scheduled_at, scheduled_at + (duration_minutes * interval '1 minute')) WITH &&
                ) WHERE (status = 'scheduled')
            ");
        }

        DB::unprepared('DROP TRIGGER IF EXISTS check_appointment_overlap ON appointments');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_overlapping_doctor_appointments()');

        DB::unprepared("
            CREATE OR REPLACE FUNCTION prevent_overlapping_doctor_appointments()
            RETURNS trigger AS \$\$
            BEGIN
                IF NEW.status = 'cancelled' OR NEW.doctor_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.status != 'scheduled' THEN
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
                      AND status = 'scheduled'
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

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS check_appointment_overlap ON appointments');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_overlapping_doctor_appointments()');

        $constraintExists = DB::selectOne("
            SELECT 1 FROM pg_constraint WHERE conname = 'no_overlapping_doctor_appointments'
        ");

        if ($constraintExists) {
            DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS no_overlapping_doctor_appointments');
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("
                ALTER TABLE appointments
                ADD CONSTRAINT no_overlapping_doctor_appointments
                EXCLUDE USING gist (
                    doctor_id WITH =,
                    tsrange(scheduled_at, scheduled_at + (duration_minutes * interval '1 minute')) WITH &&
                ) WHERE (status != 'cancelled')
            ");
        }

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
};
