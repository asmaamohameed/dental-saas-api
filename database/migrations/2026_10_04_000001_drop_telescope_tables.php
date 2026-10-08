<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('telescope_monitoring');
        Schema::dropIfExists('telescope_entries_tags');
        Schema::dropIfExists('telescope_entries');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
