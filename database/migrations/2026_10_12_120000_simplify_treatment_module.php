<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('treatment_session_steps');
        Schema::dropIfExists('treatment_template_components');
        Schema::dropIfExists('patient_treatment_components');
        Schema::dropIfExists('treatment_template_steps');

        if (Schema::hasTable('treatment_templates')) {
            Schema::table('treatment_templates', function (Blueprint $table) {
                if (Schema::hasColumn('treatment_templates', 'previous_version_id')) {
                    $table->dropForeign(['previous_version_id']);
                }
                if (Schema::hasColumn('treatment_templates', 'root_template_id')) {
                    $table->dropForeign(['root_template_id']);
                }
            });

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                DB::statement('DROP INDEX IF EXISTS treatment_templates_one_current');
            }

            Schema::table('treatment_templates', function (Blueprint $table) {
                foreach (['version', 'is_current', 'root_template_id', 'previous_version_id', 'visit_type'] as $column) {
                    if (Schema::hasColumn('treatment_templates', $column)) {
                        $table->dropColumn($column);
                    }
                }
                if (! Schema::hasColumn('treatment_templates', 'scope')) {
                    $table->string('scope')->default('tooth')->after('category');
                }
            });
        }

        if (Schema::hasTable('patient_treatments')) {
            Schema::table('patient_treatments', function (Blueprint $table) {
                if (Schema::hasColumn('patient_treatments', 'treatment_template_version')) {
                    $table->dropColumn('treatment_template_version');
                }
                if (! Schema::hasColumn('patient_treatments', 'scope')) {
                    $table->string('scope')->default('tooth')->after('treatment_template_id');
                }
                if (! Schema::hasColumn('patient_treatments', 'scope_detail')) {
                    $table->string('scope_detail', 20)->nullable()->after('scope');
                }
                if (! Schema::hasColumn('patient_treatments', 'auto_cancelled')) {
                    $table->boolean('auto_cancelled')->default(false)->after('cancelled_at');
                }
            });
        }

        if (Schema::hasTable('patient_treatment_teeth')) {
            Schema::table('patient_treatment_teeth', function (Blueprint $table) {
                if (! Schema::hasColumn('patient_treatment_teeth', 'status')) {
                    $table->string('status')->default('planned')->after('tooth_number');
                }
                if (! Schema::hasColumn('patient_treatment_teeth', 'price')) {
                    $table->decimal('price', 10, 2)->default(0)->after('status');
                }
                if (! Schema::hasColumn('patient_treatment_teeth', 'completed_at')) {
                    $table->timestamp('completed_at')->nullable()->after('price');
                }
            });

            $toothStatusForClinical = static function (string $clinicalStatus): string {
                return match ($clinicalStatus) {
                    'completed' => 'done',
                    'in_progress' => 'in_progress',
                    default => 'planned',
                };
            };

            foreach (DB::table('patient_treatments')->select('id', 'agreed_price', 'clinical_status', 'completed_at')->get() as $treatment) {
                $teeth = DB::table('patient_treatment_teeth')
                    ->where('patient_treatment_id', $treatment->id)
                    ->get();

                if ($teeth->isEmpty()) {
                    continue;
                }

                $count = $teeth->count();
                $oldTotal = (float) ($treatment->agreed_price ?? 0);
                $unitPrice = number_format($count > 0 ? $oldTotal / $count : 0, 2, '.', '');
                $status = $toothStatusForClinical((string) $treatment->clinical_status);

                DB::table('patient_treatments')
                    ->where('id', $treatment->id)
                    ->update(['agreed_price' => $unitPrice]);

                foreach ($teeth as $tooth) {
                    $payload = [
                        'price' => $unitPrice,
                        'status' => $status,
                    ];
                    if ($status === 'done') {
                        $payload['completed_at'] = $treatment->completed_at ?? now();
                    }

                    DB::table('patient_treatment_teeth')
                        ->where('id', $tooth->id)
                        ->update($payload);
                }
            }
        }

        if (Schema::hasTable('treatment_sessions')) {
            Schema::table('treatment_sessions', function (Blueprint $table) {
                if (Schema::hasColumn('treatment_sessions', 'stock_applied')) {
                    $table->dropColumn('stock_applied');
                }
                if (! Schema::hasColumn('treatment_sessions', 'tooth_numbers')) {
                    $table->json('tooth_numbers')->nullable()->after('notes');
                }
            });
        }
    }

    public function down(): void
    {
        // Irreversible simplification migration (schema + agreed_price unit backfill).
    }
};
