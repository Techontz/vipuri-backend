<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('image', 255)->nullable();
            $table->boolean('status')->default(1);
            $table->string('description', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_method_id')->constrained('shipping_methods')->cascadeOnDelete();
            $table->foreignId('shipping_zone_id')->constrained('shipping_zones')->cascadeOnDelete();
            $table->decimal('amount', 28, 8)->default(0);
            $table->decimal('min_order_amount', 28, 8)->default(0);
            $table->decimal('max_order_amount', 28, 8)->default(0);
            $table->integer('expected_delivery_days')->default(0);
            $table->boolean('status')->default(1);
            $table->boolean('is_cod')->default(1);
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('session_id', 191)->nullable()->index();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variation_id')->nullable();
            $table->longText('variation_attributes')->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 28, 8)->default(0);
            $table->decimal('original_price', 28, 8)->default(0);
            $table->decimal('offer_discount', 28, 8)->default(0);
            $table->unsignedBigInteger('offer_id')->nullable();
            $table->timestamps();
        });

        /*
         * The storefront is a stateless SPA, so the per-visitor checkout state
         * the source system kept in the PHP session (applied coupon, chosen
         * shipping rate, chosen pickup branch) is persisted here instead and
         * addressed by the same identity as the cart itself.
         */
        Schema::create('cart_contexts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('session_id', 191)->nullable()->index();
            $table->unsignedBigInteger('coupon_id')->nullable();
            $table->decimal('coupon_discount', 28, 8)->default(0);
            $table->unsignedBigInteger('shipping_rate_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->timestamps();
        });

        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('session_id', 191)->nullable()->index();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete()
                ->comment('Fulfilling branch');
            $table->unsignedBigInteger('processed_by')->nullable()->comment('admins.id — staff who last acted');
            $table->unsignedBigInteger('shipping_method_id')->nullable();
            $table->string('order_number', 80)->unique();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->unsignedBigInteger('guest_id')->default(0)->index();
            $table->tinyInteger('status')->default(0)
                ->comment('0 pending, 1 paid, 2 processing, 3 dispatched, 4 delivered, 6 returned, 7 cancelled');
            $table->tinyInteger('payment_status')->default(0)
                ->comment('0 initiated, 1 success, 2 pending, 3 rejected');
            $table->decimal('subtotal', 28, 8)->default(0);
            $table->decimal('shipping_charge', 28, 8)->default(0);
            $table->decimal('total_tax', 28, 8)->default(0);
            $table->decimal('discount', 28, 8)->default(0);
            $table->decimal('total', 28, 8)->default(0);
            $table->boolean('cod')->default(0);
            $table->unsignedBigInteger('coupon_id')->default(0);
            $table->text('shipping_address')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['status', 'payment_status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('variation_id')->default(0);
            $table->string('attribute_values', 255)->nullable();
            $table->string('product_name', 255)->nullable();
            $table->string('sku', 80)->nullable();
            $table->integer('quantity')->default(1);
            $table->decimal('price', 28, 8)->default(0);
            $table->decimal('subtotal', 28, 8)->default(0);
            $table->decimal('total_tax', 28, 8)->default(0);
            $table->timestamps();
        });

        Schema::create('order_status_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->tinyInteger('from_status')->nullable();
            $table->tinyInteger('to_status');
            $table->string('actor_type', 20)->default('admin');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->string('remark', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->string('act', 80)->nullable();
            $table->text('form_data')->nullable();
            $table->timestamps();
        });

        Schema::create('gateways', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('form_id')->default(0);
            $table->integer('code')->nullable()->index();
            $table->string('name', 80)->nullable();
            $table->string('alias', 80)->unique();
            $table->string('driver', 80)->nullable()
                ->comment('Payment driver class key; null = manual gateway');
            $table->string('image', 255)->nullable();
            $table->boolean('status')->default(1);
            $table->text('gateway_parameters')->nullable();
            $table->text('supported_currencies')->nullable();
            $table->boolean('crypto')->default(0);
            $table->text('extra')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('gateway_currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->nullable();
            $table->string('currency', 20)->nullable();
            $table->string('symbol', 20)->nullable();
            $table->integer('method_code')->nullable()->index();
            $table->string('gateway_alias', 80)->nullable();
            $table->decimal('min_amount', 28, 8)->default(0);
            $table->decimal('max_amount', 28, 8)->default(0);
            $table->decimal('percent_charge', 5, 2)->default(0);
            $table->decimal('fixed_charge', 28, 8)->default(0);
            $table->decimal('rate', 28, 8)->default(1);
            $table->text('gateway_parameter')->nullable();
            $table->timestamps();
        });

        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->unsignedBigInteger('guest_id')->default(0)->index();
            $table->unsignedBigInteger('order_id')->default(0)->index();
            $table->unsignedBigInteger('method_code')->default(0);
            $table->decimal('amount', 28, 8)->default(0);
            $table->string('method_currency', 20)->nullable();
            $table->decimal('charge', 28, 8)->default(0);
            $table->decimal('rate', 28, 8)->default(1);
            $table->decimal('final_amount', 28, 8)->default(0);
            $table->text('detail')->nullable();
            $table->string('trx', 80)->nullable()->index();
            $table->integer('payment_try')->default(0);
            $table->tinyInteger('status')->default(0)
                ->comment('0 initiated, 1 success, 2 pending, 3 rejected');
            $table->boolean('from_api')->default(0);
            $table->boolean('is_web')->default(1);
            $table->text('admin_feedback')->nullable();
            $table->string('success_url', 255)->nullable();
            $table->string('failed_url', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deposits');
        Schema::dropIfExists('gateway_currencies');
        Schema::dropIfExists('gateways');
        Schema::dropIfExists('forms');
        Schema::dropIfExists('order_status_logs');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('wishlists');
        Schema::dropIfExists('cart_contexts');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_methods');
        Schema::dropIfExists('shipping_zones');
    }
};
