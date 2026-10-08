<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillAppointmentLifecycleTimestamps extends Command
{
    protected $signature = 'appointments:backfill-lifecycle-timestamps {--dry-run : Report counts without updating}';

    protected $description = 'Backfill checked_in_at / started_at from updated_at when missing for active visit statuses';

    public function handle(): int
    {
        $checkedInNull = DB::table('appointments')
            ->where('status', 'checked_in')
            ->whereNull('checked_in_at')
            ->count();

        $startedNull = DB::table('appointments')
            ->where('status', 'in_progress')
            ->whereNull('started_at')
            ->count();

        $this->info("Rows needing checked_in_at backfill: {$checkedInNull}");
        $this->info("Rows needing started_at backfill: {$startedNull}");

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

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

        $this->info("Backfilled checked_in_at on {$checkedInUpdated} row(s).");
        $this->info("Backfilled started_at on {$startedUpdated} row(s).");

        return self::SUCCESS;
    }
}
