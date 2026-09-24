<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('treatment_template_steps')) {
            return;
        }

        if (! Schema::hasColumn('treatment_template_steps', 'is_required')) {
            Schema::table('treatment_template_steps', function (Blueprint $table) {
                $table->boolean('is_required')->default(true);
            });
        }

        if (Schema::hasColumn('treatment_template_steps', 'is_optional')) {
            DB::table('treatment_template_steps')->update([
                'is_required' => DB::raw('NOT is_optional'),
            ]);

            Schema::table('treatment_template_steps', function (Blueprint $table) {
                $table->dropColumn('is_optional');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('treatment_template_steps')) {
            return;
        }

        if (! Schema::hasColumn('treatment_template_steps', 'is_optional')) {
            Schema::table('treatment_template_steps', function (Blueprint $table) {
                $table->boolean('is_optional')->default(false);
            });
        }

        if (Schema::hasColumn('treatment_template_steps', 'is_required')) {
            DB::table('treatment_template_steps')->update([
                'is_optional' => DB::raw('NOT is_required'),
            ]);

            Schema::table('treatment_template_steps', function (Blueprint $table) {
                $table->dropColumn('is_required');
            });
        }
    }
};
