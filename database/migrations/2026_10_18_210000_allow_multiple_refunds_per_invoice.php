<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->dropUnique(['invoice_id']);
            $table->index('invoice_id');
            $table->foreignUuid('invoice_item_id')->nullable()
                ->constrained('invoice_items')->nullOnDelete();
        });

        DB::statement('ALTER TABLE refunds ADD CONSTRAINT refunds_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT refunds_amount_positive');

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('invoice_item_id');
            $table->dropIndex(['invoice_id']);
            $table->unique('invoice_id');
        });
    }
};