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
            return;
        }

        DB::unprepared("
        CREATE OR REPLACE FUNCTION prevent_overlapping_doctor_appointments()
        RETURNS trigger AS $$
        BEGIN
            PERFORM pg_advisory_xact_lock(hashtext(NEW.doctor_id::text));

            IF NEW.status != 'cancelled' AND EXISTS (
                SELECT 1 FROM appointments
                WHERE doctor_id = NEW.doctor_id
                  AND status != 'cancelled'
                  AND id != NEW.id
                  AND tsrange(scheduled_at, scheduled_at + (duration_minutes * interval '1 minute'))
                      && tsrange(NEW.scheduled_at, NEW.scheduled_at + (NEW.duration_minutes * interval '1 minute'))
            ) THEN
                RAISE EXCEPTION 'Overlapping appointment for this doctor'
                    USING ERRCODE = '23P01';
            END IF;

            RETURN NEW;
        END;
        $$ LANGUAGE plpgsql;
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
    }
};
