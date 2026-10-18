<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->decimal('cost_price', 10, 2)->default(0)->after('unit');
            $table->date('expiry_date')->nullable()->after('cost_price');
            $table->string('supplier', 255)->nullable()->after('expiry_date');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'expiry_date', 'supplier']);
        });
    }
};
