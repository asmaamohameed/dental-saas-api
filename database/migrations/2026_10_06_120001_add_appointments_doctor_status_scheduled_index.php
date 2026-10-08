<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'doctor_id', 'status', 'scheduled_at'],
                'appointments_tenant_doctor_status_scheduled_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex('appointments_tenant_doctor_status_scheduled_idx');
        });
    }
};
