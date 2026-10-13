<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'created_at', 'id'],
                'invoices_tenant_created_at_id_idx'
            );
            $table->index(
                ['tenant_id', 'status', 'created_at', 'id'],
                'invoices_tenant_status_created_at_id_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex('invoices_tenant_status_created_at_id_idx');
            $table->dropIndex('invoices_tenant_created_at_id_idx');
        });
    }
};
