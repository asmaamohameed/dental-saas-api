<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'created_at'],
                'audit_logs_tenant_created_at_idx'
            );
            $table->index(
                ['tenant_id', 'user_id', 'created_at'],
                'audit_logs_tenant_user_created_at_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_tenant_user_created_at_idx');
            $table->dropIndex('audit_logs_tenant_created_at_idx');
        });
    }
};
