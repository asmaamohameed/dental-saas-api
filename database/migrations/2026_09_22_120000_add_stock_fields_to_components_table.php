<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->decimal('current_quantity', 10, 2)->default(0)->after('unit');
            $table->decimal('minimum_threshold', 10, 2)->nullable()->after('current_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('components', function (Blueprint $table) {
            $table->dropColumn(['current_quantity', 'minimum_threshold']);
        });
    }
};
