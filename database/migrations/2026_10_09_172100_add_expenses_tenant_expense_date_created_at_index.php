<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->index(
                ['tenant_id', 'expense_date', 'created_at'],
                'expenses_tenant_expense_date_created_at_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropIndex('expenses_tenant_expense_date_created_at_idx');
        });
    }
};
