<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $available = DB::selectOne("SELECT 1 FROM pg_available_extensions WHERE name = 'btree_gist'");

        if (! $available) {
            return;
        }

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

    public function down(): void
    {
        DB::statement('ALTER TABLE appointments DROP CONSTRAINT IF EXISTS no_overlapping_doctor_appointments');
    }
};
