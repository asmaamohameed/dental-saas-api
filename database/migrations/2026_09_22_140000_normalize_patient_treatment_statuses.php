<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('patient_treatments')->where('status', 'proposed')->update(['status' => 'planned']);
        DB::table('patient_treatments')->where('status', 'paused')->update(['status' => 'planned']);
        DB::table('patient_treatments')->where('status', 'accepted')->update(['status' => 'planned']);
    }

    public function down(): void
    {
        // Legacy statuses are not restored automatically.
    }
};
