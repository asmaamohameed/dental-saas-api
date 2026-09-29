<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinic_members', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
            $table->index(['clinic_id', 'is_active']);
            $table->index('user_id');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->index('status');
            $table->index('created_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('is_active');
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('admin_user_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->string('action');
            $table->string('target_type');
            $table->string('target_id')->nullable();
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->jsonb('before_values')->nullable();
            $table->jsonb('after_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('clinic_members', function (Blueprint $table) {
            $table->dropIndex(['clinic_id', 'is_active']);
            $table->dropIndex(['user_id']);
            $table->dropColumn('is_active');
        });
    }
};
