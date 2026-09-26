<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $exists = DB::table('roles')->where('name', 'assistant')->exists();

        if (! $exists) {
            DB::table('roles')->insert([
                'name' => 'assistant',
                'label' => 'Assistant',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('name', 'assistant')->delete();
    }
};
