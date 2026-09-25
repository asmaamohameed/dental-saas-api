<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payments') || Schema::hasColumn('payments', 'deduct_amount')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('deduct_amount', 10, 2)->default(0)->after('amount');
            $table->string('deduct_reason')->nullable()->after('deduct_amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'deduct_amount')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['deduct_amount', 'deduct_reason']);
        });
    }
};
