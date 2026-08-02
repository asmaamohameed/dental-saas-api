<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->jsonb('medical_history')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX idx_patients_medical_history ON patients USING GIN (medical_history);');
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
