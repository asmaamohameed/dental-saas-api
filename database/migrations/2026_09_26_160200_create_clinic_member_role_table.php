<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_member_role', function (Blueprint $table) {
            $table->foreignUuid('clinic_member_id')->constrained('clinic_members')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->primary(['clinic_member_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinic_member_role');
    }
};
