<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('image', 255)->nullable();
            $table->text('description')->nullable();
            $table->tinyInteger('discount_type')->default(1)->comment('1 percent, 2 fixed');
            $table->decimal('amount', 28, 8)->default(0);
            $table->string('banner', 255)->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->boolean('status')->default(1);
            $table->boolean('show_on_section')->default(0);
            $table->timestamps();
        });

        Schema::create('campaign_product', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['campaign_id', 'product_id']);
        });

        Schema::create('campaign_category', function (Blueprint $table) {
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['campaign_id', 'category_id']);
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->default(0);
            $table->string('name', 191);
            $table->string('image', 255)->nullable();
            $table->string('description', 255)->nullable();
            $table->tinyInteger('discount_type')->default(1)->comment('1 percent, 2 fixed');
            $table->decimal('amount', 28, 8)->default(0);
            $table->boolean('status')->default(1);
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->integer('priority')->default(1);
            $table->boolean('apply_once_per_cart')->default(0);
            $table->boolean('show_on_section')->default(0);
            $table->timestamps();
        });

        Schema::create('offer_product', function (Blueprint $table) {
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['offer_id', 'product_id']);
        });

        Schema::create('offer_category', function (Blueprint $table) {
            $table->foreignId('offer_id')->constrained('offers')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->primary(['offer_id', 'category_id']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('code', 80)->unique();
            $table->tinyInteger('discount_type')->default(1)
                ->comment('1 percent, 2 fixed cart, 3 fixed product');
            $table->decimal('amount', 28, 8)->default(0);
            $table->decimal('max_discount', 28, 8)->default(0);
            $table->boolean('status')->default(1);
            $table->date('expiry_date')->nullable();
            $table->integer('limit_per_coupon')->nullable();
            $table->integer('limit_per_customer')->nullable();
            $table->decimal('minimum_spend', 28, 8)->default(0);
            $table->decimal('maximum_spend', 28, 8)->default(0);
            $table->integer('total_uses')->default(0);
            $table->boolean('exclude_sale_items')->default(0);
            $table->boolean('exclude_offers')->default(0);
            $table->timestamps();
        });

        Schema::create('coupon_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('coupon_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->unsignedBigInteger('order_id')->default(0)->index();
            $table->timestamps();
        });

        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
        Schema::dropIfExists('coupon_usages');
        Schema::dropIfExists('coupon_category');
        Schema::dropIfExists('coupon_product');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('offer_category');
        Schema::dropIfExists('offer_product');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('campaign_category');
        Schema::dropIfExists('campaign_product');
        Schema::dropIfExists('campaigns');
    }
};
