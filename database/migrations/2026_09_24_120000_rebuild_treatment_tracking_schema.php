<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->wipePatientTreatmentData();
        $this->detachAppointmentAndInvoiceVisitLinks();
        $this->replaceTemplateVisitStructure();
        $this->versionTemplates();
        $this->rebuildPatientTreatments();
        $this->createPlansTeethSessions();
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_treatment_components');
        Schema::dropIfExists('treatment_session_steps');
        Schema::dropIfExists('treatment_sessions');
        Schema::dropIfExists('patient_treatment_teeth');

        Schema::table('patient_treatments', function (Blueprint $table) {
            if (Schema::hasColumn('patient_treatments', 'treatment_plan_id')) {
                $table->dropConstrainedForeignId('treatment_plan_id');
            }
            if (Schema::hasColumn('patient_treatments', 'parent_treatment_id')) {
                $table->dropConstrainedForeignId('parent_treatment_id');
            }
            if (Schema::hasColumn('patient_treatments', 'dentist_id')) {
                $table->dropConstrainedForeignId('dentist_id');
            }
        });

        Schema::dropIfExists('treatment_plans');

        Schema::table('patient_treatments', function (Blueprint $table) {
            foreach ([
                'treatment_template_version',
                'agreed_price',
                'clinical_status',
                'cancellation_reason',
                'financial_status',
                'consent_status',
                'consent_document_ref',
                'treatment_type',
                'cancelled_at',
            ] as $column) {
                if (Schema::hasColumn('patient_treatments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('patient_treatments', function (Blueprint $table) {
            if (! Schema::hasColumn('patient_treatments', 'doctor_id')) {
                $table->foreignUuid('doctor_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('patient_treatments', 'tooth_number')) {
                $table->string('tooth_number')->nullable();
            }
            if (! Schema::hasColumn('patient_treatments', 'status')) {
                $table->string('status')->default('planned');
            }
            if (! Schema::hasColumn('patient_treatments', 'total_visits')) {
                $table->unsignedInteger('total_visits')->default(1);
            }
            if (! Schema::hasColumn('patient_treatments', 'actual_price')) {
                $table->decimal('actual_price', 10, 2)->default(0);
            }
        });

        Schema::table('treatment_templates', function (Blueprint $table) {
            if (Schema::hasColumn('treatment_templates', 'previous_version_id')) {
                $table->dropConstrainedForeignId('previous_version_id');
            }
            if (Schema::hasColumn('treatment_templates', 'root_template_id')) {
                $table->dropConstrainedForeignId('root_template_id');
            }
            foreach (['version', 'is_current'] as $column) {
                if (Schema::hasColumn('treatment_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('treatment_template_components');
        Schema::dropIfExists('treatment_template_steps');

        Schema::create('treatment_template_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('treatment_template_id')->constrained('treatment_templates')->cascadeOnDelete();
            $table->unsignedInteger('visit_order');
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('estimated_duration_minutes')->default(30);
            $table->timestamps();
            $table->unique(['treatment_template_id', 'visit_order']);
        });

        Schema::create('treatment_template_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('treatment_template_visit_id')->constrained('treatment_template_visits')->cascadeOnDelete();
            $table->foreignUuid('component_id')->constrained('components')->restrictOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('free_quantity', 10, 2)->default(0);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('patient_treatment_visits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('patient_treatment_id')->constrained('patient_treatments')->cascadeOnDelete();
            $table->unsignedInteger('visit_order');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status')->default('planned');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('scheduled_date')->nullable();
            $table->timestamp('completed_date')->nullable();
            $table->foreignUuid('dentist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->text('status_reason')->nullable();
            $table->decimal('visit_price', 10, 2)->nullable();
            $table->timestamps();
            $table->unique(['patient_treatment_id', 'visit_order']);
        });

        Schema::create('patient_treatment_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('patient_treatment_visit_id')->constrained('patient_treatment_visits')->cascadeOnDelete();
            $table->foreignUuid('component_id')->nullable()->constrained('components')->nullOnDelete();
            $table->string('name');
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('free_quantity', 10, 2)->default(0);
            $table->string('unit')->default('piece');
            $table->foreignUuid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('appointments', function (Blueprint $table) {
            if (! Schema::hasColumn('appointments', 'patient_treatment_visit_id')) {
                $table->foreignUuid('patient_treatment_visit_id')->nullable()->constrained('patient_treatment_visits')->nullOnDelete();
            }
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('invoice_items', 'patient_treatment_visit_id')) {
                $table->foreignUuid('patient_treatment_visit_id')->nullable()->constrained('patient_treatment_visits')->nullOnDelete();
            }
        });
    }

    private function wipePatientTreatmentData(): void
    {
        if (Schema::hasTable('invoice_items')) {
            DB::table('invoice_items')->whereNotNull('patient_treatment_id')->delete();
        }

        if (Schema::hasTable('patient_treatment_components')) {
            DB::table('patient_treatment_components')->delete();
        }

        if (Schema::hasTable('patient_treatment_visits')) {
            DB::table('patient_treatment_visits')->delete();
        }

        if (Schema::hasTable('patient_treatments')) {
            DB::table('patient_treatments')->delete();
        }
    }

    private function detachAppointmentAndInvoiceVisitLinks(): void
    {
        if (Schema::hasColumn('appointments', 'patient_treatment_visit_id')) {
            Schema::table('appointments', function (Blueprint $table) {
                $table->dropConstrainedForeignId('patient_treatment_visit_id');
            });
        }

        if (Schema::hasColumn('invoice_items', 'patient_treatment_visit_id')) {
            Schema::table('invoice_items', function (Blueprint $table) {
                $table->dropConstrainedForeignId('patient_treatment_visit_id');
            });
        }
    }

    private function replaceTemplateVisitStructure(): void
    {
        Schema::create('treatment_template_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('treatment_template_id')->constrained('treatment_templates')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('step_order');
            $table->boolean('is_optional')->default(false);
            $table->boolean('is_repeatable')->default(false);
            $table->unsignedInteger('default_duration_minutes')->default(30);
            $table->timestamps();

            $table->unique(['treatment_template_id', 'step_order']);
        });

        $visitToStep = [];
        $now = now();

        if (Schema::hasTable('treatment_template_visits')) {
            $visits = DB::table('treatment_template_visits')->orderBy('visit_order')->get();

            foreach ($visits as $visit) {
                $stepId = (string) Str::uuid();
                $visitToStep[$visit->id] = $stepId;

                DB::table('treatment_template_steps')->insert([
                    'id' => $stepId,
                    'tenant_id' => $visit->tenant_id,
                    'treatment_template_id' => $visit->treatment_template_id,
                    'name_ar' => $visit->name_ar,
                    'name_en' => $visit->name_en,
                    'description' => $visit->description,
                    'step_order' => $visit->visit_order,
                    'is_optional' => false,
                    'is_repeatable' => false,
                    'default_duration_minutes' => $visit->estimated_duration_minutes ?? 30,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        $oldComponents = Schema::hasTable('treatment_template_components')
            ? DB::table('treatment_template_components')->get()
            : collect();

        Schema::dropIfExists('treatment_template_components');

        Schema::create('treatment_template_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('treatment_template_step_id')->constrained('treatment_template_steps')->cascadeOnDelete();
            $table->foreignUuid('component_id')->constrained('components')->restrictOnDelete();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('free_quantity', 10, 2)->default(0);
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->timestamps();
        });

        foreach ($oldComponents as $component) {
            $stepId = $visitToStep[$component->treatment_template_visit_id] ?? null;
            if (! $stepId) {
                continue;
            }

            DB::table('treatment_template_components')->insert([
                'id' => $component->id,
                'tenant_id' => $component->tenant_id,
                'treatment_template_step_id' => $stepId,
                'component_id' => $component->component_id,
                'quantity' => $component->quantity,
                'free_quantity' => $component->free_quantity,
                'unit_price' => $component->unit_price,
                'created_at' => $component->created_at ?? $now,
                'updated_at' => $component->updated_at ?? $now,
            ]);
        }

        Schema::dropIfExists('patient_treatment_components');
        Schema::dropIfExists('patient_treatment_visits');
        Schema::dropIfExists('treatment_template_visits');

        $templatesWithoutSteps = DB::table('treatment_templates')
            ->whereNotIn('id', DB::table('treatment_template_steps')->select('treatment_template_id'))
            ->get();

        foreach ($templatesWithoutSteps as $template) {
            DB::table('treatment_template_steps')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $template->tenant_id,
                'treatment_template_id' => $template->id,
                'name_ar' => 'جلسة قياسية',
                'name_en' => 'Standard step',
                'description' => null,
                'step_order' => 1,
                'is_optional' => false,
                'is_repeatable' => false,
                'default_duration_minutes' => $template->estimated_duration_minutes ?? 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function versionTemplates(): void
    {
        Schema::table('treatment_templates', function (Blueprint $table) {
            if (! Schema::hasColumn('treatment_templates', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('is_active');
            }
            if (! Schema::hasColumn('treatment_templates', 'is_current')) {
                $table->boolean('is_current')->default(true)->after('version');
            }
            if (! Schema::hasColumn('treatment_templates', 'root_template_id')) {
                $table->uuid('root_template_id')->nullable()->after('is_current');
            }
            if (! Schema::hasColumn('treatment_templates', 'previous_version_id')) {
                $table->uuid('previous_version_id')->nullable()->after('root_template_id');
            }
        });

        DB::table('treatment_templates')->orderBy('created_at')->get()->each(function ($template) {
            DB::table('treatment_templates')->where('id', $template->id)->update([
                'version' => $template->version ?: 1,
                'is_current' => true,
                'root_template_id' => $template->id,
                'previous_version_id' => null,
            ]);
        });

        Schema::table('treatment_templates', function (Blueprint $table) {
            $table->foreign('root_template_id')->references('id')->on('treatment_templates')->nullOnDelete();
            $table->foreign('previous_version_id')->references('id')->on('treatment_templates')->nullOnDelete();
            $table->index(['tenant_id', 'root_template_id', 'is_current']);
            $table->unique(['tenant_id', 'root_template_id', 'version']);
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX treatment_templates_one_current ON treatment_templates (tenant_id, root_template_id) WHERE is_current = true');
        }
    }

    private function rebuildPatientTreatments(): void
    {
        Schema::table('patient_treatments', function (Blueprint $table) {
            if (Schema::hasColumn('patient_treatments', 'doctor_id')) {
                $table->dropConstrainedForeignId('doctor_id');
            }

            foreach (['tooth_number', 'status', 'total_visits', 'actual_price'] as $column) {
                if (Schema::hasColumn('patient_treatments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('patient_treatments', function (Blueprint $table) {
            if (! Schema::hasColumn('patient_treatments', 'treatment_template_version')) {
                $table->unsignedInteger('treatment_template_version')->default(1)->after('treatment_template_id');
            }
            if (! Schema::hasColumn('patient_treatments', 'agreed_price')) {
                $table->decimal('agreed_price', 10, 2)->default(0)->after('treatment_template_version');
            }
            if (! Schema::hasColumn('patient_treatments', 'clinical_status')) {
                $table->string('clinical_status')->default('planned')->after('agreed_price');
            }
            if (! Schema::hasColumn('patient_treatments', 'cancellation_reason')) {
                $table->text('cancellation_reason')->nullable()->after('clinical_status');
            }
            if (! Schema::hasColumn('patient_treatments', 'financial_status')) {
                $table->string('financial_status')->default('unpaid')->after('cancellation_reason');
            }
            if (! Schema::hasColumn('patient_treatments', 'consent_status')) {
                $table->string('consent_status')->default('not_required')->after('financial_status');
            }
            if (! Schema::hasColumn('patient_treatments', 'consent_document_ref')) {
                $table->string('consent_document_ref')->nullable()->after('consent_status');
            }
            if (! Schema::hasColumn('patient_treatments', 'parent_treatment_id')) {
                $table->uuid('parent_treatment_id')->nullable()->after('consent_document_ref');
            }
            if (! Schema::hasColumn('patient_treatments', 'treatment_type')) {
                $table->string('treatment_type')->default('original')->after('parent_treatment_id');
            }
            if (! Schema::hasColumn('patient_treatments', 'dentist_id')) {
                $table->foreignUuid('dentist_id')->nullable()->after('treatment_type')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('patient_treatments', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            }
            if (! Schema::hasColumn('patient_treatments', 'treatment_plan_id')) {
                $table->uuid('treatment_plan_id')->nullable()->after('patient_id');
            }

            $table->index(['tenant_id', 'clinical_status']);
            $table->index(['tenant_id', 'financial_status']);
        });

        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->foreign('parent_treatment_id')->references('id')->on('patient_treatments')->nullOnDelete();
        });
    }

    private function createPlansTeethSessions(): void
    {
        Schema::create('treatment_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('title');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('patient_treatments', function (Blueprint $table) {
            $table->foreign('treatment_plan_id')->references('id')->on('treatment_plans')->nullOnDelete();
        });

        Schema::create('patient_treatment_teeth', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('patient_treatment_id')->constrained('patient_treatments')->cascadeOnDelete();
            $table->string('tooth_number', 2);
            $table->timestamps();

            $table->unique(['patient_treatment_id', 'tooth_number']);
            $table->index(['tenant_id', 'tooth_number']);
        });

        Schema::create('treatment_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('patient_treatment_id')->constrained('patient_treatments')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('appointments')->nullOnDelete();
            $table->foreignUuid('dentist_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('session_date');
            $table->string('status')->default('scheduled');
            $table->text('notes')->nullable();
            $table->boolean('stock_applied')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'patient_treatment_id']);
            $table->index(['tenant_id', 'appointment_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('treatment_session_steps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('treatment_session_id')->constrained('treatment_sessions')->cascadeOnDelete();
            $table->foreignUuid('treatment_template_step_id')->nullable()->constrained('treatment_template_steps')->nullOnDelete();
            $table->string('custom_step_name')->nullable();
            $table->text('custom_step_description')->nullable();
            $table->unsignedInteger('occurrence_number')->default(1);
            $table->string('status')->default('done');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'treatment_session_id']);
        });

        Schema::create('patient_treatment_components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('treatment_session_id')->constrained('treatment_sessions')->cascadeOnDelete();
            $table->foreignUuid('component_id')->nullable()->constrained('components')->nullOnDelete();
            $table->string('name');
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('free_quantity', 10, 2)->default(0);
            $table->string('unit')->default('piece');
            $table->foreignUuid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->timestamps();
        });
    }
};
