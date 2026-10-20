<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignUuid('treatment_plan_id')
                ->nullable()
                ->after('appointment_id')
                ->constrained('treatment_plans')
                ->restrictOnDelete();
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignUuid('treatment_plan_id')
                ->nullable()
                ->after('patient_id')
                ->constrained('treatment_plans')
                ->restrictOnDelete();
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignUuid('patient_treatment_tooth_id')
                ->nullable()
                ->after('patient_treatment_id')
                ->constrained('patient_treatment_teeth')
                ->restrictOnDelete();
            $table->foreignUuid('appointment_id')
                ->nullable()
                ->after('patient_treatment_tooth_id')
                ->constrained('appointments')
                ->restrictOnDelete();
            $table->timestamp('voided_at')->nullable()->after('appointment_id');
            $table->text('void_reason')->nullable()->after('voided_at');
            $table->foreignUuid('voided_by')
                ->nullable()
                ->after('void_reason')
                ->constrained('users')
                ->restrictOnDelete();
        });

        // Void columns are all-or-nothing. A visit line cannot also be a tooth or treatment line.
        DB::statement('
            ALTER TABLE invoice_items
            ADD CONSTRAINT invoice_items_void_columns_check
            CHECK (
                (voided_at IS NULL AND void_reason IS NULL AND voided_by IS NULL)
                OR
                (voided_at IS NOT NULL AND void_reason IS NOT NULL AND voided_by IS NOT NULL)
            )
        ');

        DB::statement('
            ALTER TABLE invoice_items
            ADD CONSTRAINT invoice_items_tooth_appointment_exclusive_check
            CHECK (NOT (patient_treatment_tooth_id IS NOT NULL AND appointment_id IS NOT NULL))
        ');

        DB::statement('
            ALTER TABLE invoice_items
            ADD CONSTRAINT invoice_items_visit_excludes_treatment_check
            CHECK (appointment_id IS NULL OR patient_treatment_id IS NULL)
        ');

        // One live plan invoice per plan. Soft-deleted invoices free the slot.
        DB::statement('
            CREATE UNIQUE INDEX invoices_one_active_plan_uidx
            ON invoices (tenant_id, treatment_plan_id)
            WHERE treatment_plan_id IS NOT NULL AND deleted_at IS NULL
        ');

        // Voided tooth lines are excluded so a retreat can be billed after a void.
        DB::statement('
            CREATE UNIQUE INDEX invoice_items_one_active_tooth_uidx
            ON invoice_items (patient_treatment_tooth_id)
            WHERE patient_treatment_tooth_id IS NOT NULL
                AND deleted_at IS NULL
                AND voided_at IS NULL
        ');

        // One visit line per appointment until that line is deleted. Void does not free the slot.
        DB::statement('
            CREATE UNIQUE INDEX invoice_items_one_active_appointment_uidx
            ON invoice_items (appointment_id)
            WHERE appointment_id IS NOT NULL AND deleted_at IS NULL
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE invoice_items DROP CONSTRAINT IF EXISTS invoice_items_void_columns_check');
        DB::statement('ALTER TABLE invoice_items DROP CONSTRAINT IF EXISTS invoice_items_tooth_appointment_exclusive_check');
        DB::statement('ALTER TABLE invoice_items DROP CONSTRAINT IF EXISTS invoice_items_visit_excludes_treatment_check');

        DB::statement('DROP INDEX IF EXISTS invoice_items_one_active_tooth_uidx');
        DB::statement('DROP INDEX IF EXISTS invoice_items_one_active_appointment_uidx');
        DB::statement('DROP INDEX IF EXISTS invoices_one_active_plan_uidx');

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropConstrainedForeignId('appointment_id');
            $table->dropConstrainedForeignId('patient_treatment_tooth_id');
            $table->dropColumn(['voided_at', 'void_reason']);
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treatment_plan_id');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('treatment_plan_id');
        });
    }
};
