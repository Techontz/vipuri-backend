<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-aware inventory.
 *
 * One global catalogue + one `branch_inventories` row per (branch, product,
 * variation). `products.stock_quantity` remains the company-wide total and is
 * kept in sync from branch rows, so every storefront query that the source
 * system performed against `products.stock_quantity` keeps working unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variation_id')->default(0);
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0)->comment('Held by unfulfilled orders');
            $table->integer('min_stock_quantity')->default(0)->comment('Low-stock threshold for this branch');
            $table->string('shelf_location', 80)->nullable();
            $table->decimal('cost_price', 28, 8)->default(0);
            $table->timestamp('last_counted_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'product_id', 'variation_id'], 'branch_product_variation_unique');
            $table->index(['product_id', 'variation_id']);
        });

        Schema::create('stock_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variation_id')->default(0);
            $table->unsignedBigInteger('order_id')->nullable()->index();
            $table->integer('change_quantity')->default(0);
            $table->integer('post_quantity')->default(0);
            $table->string('remark', 80)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('actor_type', 20)->default('system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'branch_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 60)->unique();
            $table->foreignId('from_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->tinyInteger('status')->default(0)
                ->comment('0 pending, 1 in transit, 2 received, 3 cancelled');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->unsignedBigInteger('received_by')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index(['from_branch_id', 'status']);
            $table->index(['to_branch_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained('stock_transfers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variation_id')->default(0);
            $table->integer('quantity')->default(0);
            $table->integer('received_quantity')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
        Schema::dropIfExists('stock_logs');
        Schema::dropIfExists('branch_inventories');
    }
};
