<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staged for a later deploy. This file is outside database/migrations on purpose
 * so `php artisan migrate` does not drop users.role until the new tables are verified.
 * Move it into database/migrations before that deploy.
 *
 * users.additional_roles is included only when that column exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $drops = [];

            if (Schema::hasColumn('users', 'role')) {
                $drops[] = 'role';
            }

            if (Schema::hasColumn('users', 'additional_roles')) {
                $drops[] = 'additional_roles';
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->string('role')->nullable();
            }

            if (! Schema::hasColumn('users', 'additional_roles')) {
                $table->json('additional_roles')->nullable();
            }
        });
    }
};
