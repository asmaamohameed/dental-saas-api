<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone', 50);
            $table->string('contact_person')->nullable();
            $table->string('address', 500)->nullable();
            $table->unsignedSmallInteger('default_turnaround_days')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('lab_works', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->restrictOnDelete();
            $table->foreignUuid('lab_id')->constrained('labs')->restrictOnDelete();
            $table->string('description');
            $table->text('notes')->nullable();
            $table->date('sent_on');
            $table->date('expected_on');
            $table->date('received_on')->nullable();
            $table->string('status')->default('at_lab');
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'expected_on']);
            $table->index(['tenant_id', 'lab_id']);
            $table->index(['tenant_id', 'patient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_works');
        Schema::dropIfExists('labs');
    }
};
