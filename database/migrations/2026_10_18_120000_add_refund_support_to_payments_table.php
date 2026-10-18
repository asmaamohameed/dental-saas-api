<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('type')->default('payment')->after('invoice_id');
            $table->foreignUuid('invoice_item_id')->nullable()->after('type')->constrained('invoice_items')->restrictOnDelete();
            $table->foreignUuid('refunded_payment_id')->nullable()->after('invoice_item_id')->constrained('payments')->restrictOnDelete();
            $table->text('reason')->nullable()->after('notes');
            $table->index(['tenant_id', 'invoice_id', 'type'], 'payments_tenant_invoice_type_index');
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_type_check CHECK (type IN ('payment', 'refund'))");
        // Payment rows may be a pure write-off (amount 0, deduct_amount > 0). Refund rows may not.
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_payment_amount_check CHECK (type <> 'payment' OR amount > 0 OR deduct_amount > 0)");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_refund_amount_check CHECK (type = 'payment' OR amount > 0)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_refund_amount_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_payment_amount_check');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_type_check');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_tenant_invoice_type_index');
            $table->dropConstrainedForeignId('refunded_payment_id');
            $table->dropConstrainedForeignId('invoice_item_id');
            $table->dropColumn(['type', 'reason']);
        });
    }
};
