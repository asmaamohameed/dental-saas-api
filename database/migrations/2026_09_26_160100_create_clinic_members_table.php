<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Clinics are stored in tenants, and tenants.id / users.id are UUIDs.
            $table->foreignUuid('clinic_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['clinic_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_members');
    }
};
