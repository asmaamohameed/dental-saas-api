<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_treatment_visits', function (Blueprint $table) {
            $table->decimal('visit_price', 10, 2)->nullable()->after('status_reason');
        });
    }

    public function down(): void
    {
        Schema::table('patient_treatment_visits', function (Blueprint $table) {
            $table->dropColumn('visit_price');
        });
    }
};
