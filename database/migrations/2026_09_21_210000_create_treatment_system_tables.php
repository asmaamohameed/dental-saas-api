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
        Schema::create('components', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->decimal('default_price', 10, 2)->default(0);
            $table->string('unit')->default('piece');
            $table->foreignUuid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('treatment_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->string('category')->default('medical');
            $table->text('description')->nullable();
            $table->decimal('default_price', 10, 2)->default(0);
            $table->unsignedInteger('estimated_duration_minutes')->default(30);
            $table->string('visit_type')->default('single_visit');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'category']);
        });

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

        Schema::create('patient_treatments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignUuid('treatment_template_id')->nullable()->constrained('treatment_templates')->nullOnDelete();
            $table->foreignUuid('doctor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tooth_number')->nullable();
            $table->text('diagnosis')->nullable();
            $table->string('status')->default('proposed');
            $table->string('priority')->default('routine');
            $table->decimal('actual_price', 10, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'patient_id']);
            $table->index(['tenant_id', 'status']);
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
            $table->timestamps();

            $table->unique(['patient_treatment_id', 'visit_order']);
            $table->index(['tenant_id', 'status']);
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

        $now = now();
        $services = DB::table('services')->get();

        foreach ($services as $service) {
            DB::table('treatment_templates')->insert([
                'id' => $service->id,
                'tenant_id' => $service->tenant_id,
                'name_ar' => $service->name_ar,
                'name_en' => $service->name_en,
                'category' => 'medical',
                'description' => null,
                'default_price' => $service->default_price,
                'estimated_duration_minutes' => 30,
                'visit_type' => 'single_visit',
                'is_active' => $service->is_active,
                'created_at' => $service->created_at ?? $now,
                'updated_at' => $service->updated_at ?? $now,
            ]);

            DB::table('treatment_template_visits')->insert([
                'id' => (string) Str::uuid(),
                'tenant_id' => $service->tenant_id,
                'treatment_template_id' => $service->id,
                'visit_order' => 1,
                'name_ar' => 'جلسة قياسية',
                'name_en' => 'Standard Session',
                'description' => null,
                'estimated_duration_minutes' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_treatment_components');
        Schema::dropIfExists('patient_treatment_visits');
        Schema::dropIfExists('patient_treatments');
        Schema::dropIfExists('treatment_template_components');
        Schema::dropIfExists('treatment_template_visits');
        Schema::dropIfExists('treatment_templates');
        Schema::dropIfExists('components');
    }
};
