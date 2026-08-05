<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tooth_records', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);
        });

        Schema::table('tooth_records', function (Blueprint $table) {
            $table->foreign('patient_id')
                ->references('id')->on('patients')
                ->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tooth_records', function (Blueprint $table) {
            $table->dropForeign(['patient_id']);
        });

        Schema::table('tooth_records', function (Blueprint $table) {
            $table->foreign('patient_id')
                ->references('id')->on('patients')
                ->cascadeOnDelete();
        });
    }
};
