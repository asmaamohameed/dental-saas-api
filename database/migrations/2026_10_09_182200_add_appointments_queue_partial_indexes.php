<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE INDEX appointments_tenant_doctor_checked_in_at_idx
            ON appointments (tenant_id, doctor_id, checked_in_at)
            WHERE status = 'checked_in' AND checked_in_at IS NOT NULL
        ");

        DB::statement("
            CREATE INDEX appointments_tenant_doctor_started_at_idx
            ON appointments (tenant_id, doctor_id, started_at)
            WHERE status = 'in_progress' AND started_at IS NOT NULL
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS appointments_tenant_doctor_started_at_idx');
        DB::statement('DROP INDEX IF EXISTS appointments_tenant_doctor_checked_in_at_idx');
    }
};
