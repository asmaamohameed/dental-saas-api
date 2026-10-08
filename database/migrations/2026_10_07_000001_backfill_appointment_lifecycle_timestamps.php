<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $checkedInUpdated = DB::table('appointments')
            ->where('status', 'checked_in')
            ->whereNull('checked_in_at')
            ->update([
                'checked_in_at' => DB::raw('updated_at'),
                'updated_at' => DB::raw('updated_at'),
            ]);

        $startedUpdated = DB::table('appointments')
            ->where('status', 'in_progress')
            ->whereNull('started_at')
            ->update([
                'started_at' => DB::raw('updated_at'),
                'updated_at' => DB::raw('updated_at'),
            ]);

    }

    public function down(): void
    {
        // Non-destructive backfill; no automatic rollback.
    }
};
