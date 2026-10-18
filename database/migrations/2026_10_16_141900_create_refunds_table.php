<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('invoice_id')->unique()->constrained('invoices')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('reason_code');
            $table->string('reason_note', 500)->nullable();
            $table->string('method');
            $table->foreignUuid('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('refunded_at');
            $table->timestamps();

            $table->index(['tenant_id', 'refunded_at']);
            $table->index(['tenant_id', 'reason_code']);
            $table->index(['tenant_id', 'refunded_by']);
            $table->index(['tenant_id', 'method']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
