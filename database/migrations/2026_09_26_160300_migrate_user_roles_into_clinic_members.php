<?php

use App\Support\Roles\ClinicMembershipSync;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        ClinicMembershipSync::backfillMissing();
    }

    public function down(): void
    {
        // Membership rows are removed with the clinic_members table.
    }
};
