<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->timestamps();
        });

        $now = now();

        DB::table('roles')->insert([
            ['name' => 'owner', 'label' => 'Clinic Owner', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'doctor', 'label' => 'Doctor', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'assistant', 'label' => 'Assistant', 'created_at' => $now, 'updated_at' => $now],
            // Receptionist already exists on users.role and must survive the copy.
            ['name' => 'receptionist', 'label' => 'Receptionist', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
