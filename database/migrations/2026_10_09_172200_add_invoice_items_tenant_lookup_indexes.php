<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'invoice_id'],
                'invoice_items_tenant_invoice_id_idx'
            );
            $table->index(
                ['tenant_id', 'patient_treatment_id'],
                'invoice_items_tenant_patient_treatment_id_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropIndex('invoice_items_tenant_invoice_id_idx');
            $table->dropIndex('invoice_items_tenant_patient_treatment_id_idx');
        });
    }
};
