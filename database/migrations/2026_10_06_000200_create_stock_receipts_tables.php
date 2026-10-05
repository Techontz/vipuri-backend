<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods received notes.
 *
 * A stock receipt records a delivery booked into one branch: who supplied it,
 * the supplier's invoice number, and what each line cost. The stock itself is
 * moved through InventoryService::adjust(), so `stock_logs` and the catalogue
 * roll-up stay the single source of truth; this table is the paper trail.
 *
 * Additive only — safe on a live database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            // Filled straight after insert from the row id (GRN-DOM-000123),
            // which keeps numbers unique and sequential without a counter table.
            $table->string('reference', 60)->nullable()->unique();
            $table->string('supplier_name', 191)->nullable();
            $table->string('supplier_reference', 191)->nullable()->comment('Supplier invoice / delivery note number');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('received_by')->nullable()->index();
            $table->timestamp('received_at')->nullable();
            $table->decimal('total_cost', 28, 8)->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'received_at']);
            $table->index('supplier_name');
        });

        Schema::create('stock_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_receipt_id')->constrained('stock_receipts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variation_id')->default(0);
            $table->integer('quantity')->default(0);
            $table->decimal('unit_cost', 28, 8)->nullable();
            $table->decimal('line_cost', 28, 8)->default(0);
            $table->timestamps();

            $table->index(['product_id', 'variation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_receipt_items');
        Schema::dropIfExists('stock_receipts');
    }
};
