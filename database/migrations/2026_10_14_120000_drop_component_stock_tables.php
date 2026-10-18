<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('treatment_sessions') && Schema::hasColumn('treatment_sessions', 'stock_applied')) {
            Schema::table('treatment_sessions', function (Blueprint $table) {
                $table->dropColumn('stock_applied');
            });
        }

        Schema::dropIfExists('patient_treatment_components');
        Schema::dropIfExists('treatment_template_components');
        Schema::dropIfExists('component_stock_movements');
        Schema::dropIfExists('components');
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Cannot reverse drop_component_stock_tables: components and stock movement tables were removed intentionally.'
        );
    }
};
