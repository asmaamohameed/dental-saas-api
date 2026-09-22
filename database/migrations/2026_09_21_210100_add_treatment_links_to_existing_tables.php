<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignUuid('patient_treatment_visit_id')
                ->nullable()
                ->after('doctor_id')
                ->constrained('patient_treatment_visits')
                ->nullOnDelete();
            $table->string('appointment_type')->default('consultation')->after('status');

            $table->index(['tenant_id', 'patient_treatment_visit_id']);
            $table->index(['tenant_id', 'appointment_type']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignUuid('patient_treatment_id')
                ->nullable()
                ->after('service_id')
                ->constrained('patient_treatments')
                ->nullOnDelete();
            $table->foreignUuid('patient_treatment_visit_id')
                ->nullable()
                ->after('patient_treatment_id')
                ->constrained('patient_treatment_visits')
                ->nullOnDelete();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignUuid('service_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignUuid('service_id')->nullable(false)->change();
            $table->dropConstrainedForeignId('patient_treatment_visit_id');
            $table->dropConstrainedForeignId('patient_treatment_id');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'appointment_type']);
            $table->dropIndex(['tenant_id', 'patient_treatment_visit_id']);
            $table->dropColumn('appointment_type');
            $table->dropConstrainedForeignId('patient_treatment_visit_id');
        });
    }
};
