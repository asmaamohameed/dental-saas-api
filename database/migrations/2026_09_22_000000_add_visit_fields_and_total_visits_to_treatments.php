<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patient_treatments', function (Blueprint $table) {
            if (! Schema::hasColumn('patient_treatments', 'total_visits')) {
                $table->unsignedInteger('total_visits')->default(1)->after('priority');
            }
        });

        Schema::table('patient_treatment_visits', function (Blueprint $table) {
            if (! Schema::hasColumn('patient_treatment_visits', 'scheduled_date')) {
                $table->timestamp('scheduled_date')->nullable()->after('status');
            }
            if (! Schema::hasColumn('patient_treatment_visits', 'completed_date')) {
                $table->timestamp('completed_date')->nullable()->after('scheduled_date');
            }
            if (! Schema::hasColumn('patient_treatment_visits', 'dentist_id')) {
                $table->foreignUuid('dentist_id')->nullable()->after('completed_date')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('patient_treatment_visits', 'appointment_id')) {
                $table->foreignUuid('appointment_id')->nullable()->after('dentist_id')->constrained('appointments')->nullOnDelete();
            }
            if (! Schema::hasColumn('patient_treatment_visits', 'status_reason')) {
                $table->text('status_reason')->nullable()->after('appointment_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('patient_treatment_visits', function (Blueprint $table) {
            if (Schema::hasColumn('patient_treatment_visits', 'appointment_id')) {
                $table->dropConstrainedForeignId('appointment_id');
            }
            if (Schema::hasColumn('patient_treatment_visits', 'dentist_id')) {
                $table->dropConstrainedForeignId('dentist_id');
            }
            $table->dropColumn(['scheduled_date', 'completed_date', 'status_reason']);
        });

        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->dropColumn('total_visits');
        });
    }
};
