<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignUuid('service_id')
                ->nullable()
                ->after('appointment_type')
                ->constrained('treatment_templates')
                ->restrictOnDelete();

            $table->decimal('fee', 12, 2)->nullable()->after('service_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn('fee');
        });
    }
};
