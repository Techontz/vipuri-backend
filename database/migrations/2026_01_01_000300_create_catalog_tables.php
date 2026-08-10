<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global (company-wide) product catalogue. VIPURI owns one catalogue; per-branch
 * stock lives in `branch_inventories` (see the inventory migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $table->boolean('status')->default(1);
            $table->unsignedInteger('position')->nullable();
            $table->string('name', 191);
            $table->string('slug', 191)->unique();
            $table->string('description', 255)->nullable();
            $table->string('icon', 255)->nullable();
            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->text('meta_keywords')->nullable();
            $table->string('image', 255)->nullable();
            $table->boolean('show_in_navbar')->default(0);
            $table->boolean('is_top')->default(0);
            $table->boolean('is_popular')->default(0);
            $table->timestamps();

            $table->index(['status', 'parent_id']);
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('logo', 255)->nullable();
            $table->string('slug', 191)->unique();
            $table->text('seo_content')->nullable();
            $table->boolean('is_popular')->default(0);
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('taxes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->decimal('rate', 10, 2)->default(0);
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('stock_units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('shipping_classes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('control_type', 40)->nullable()->comment('dropdown | radio | color | image | button');
            $table->boolean('is_global')->default(0);
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('color_code', 40)->nullable();
            $table->boolean('is_pre_selected')->default(0);
            $table->string('image', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->unsignedBigInteger('brand_id')->default(0)->index();
            $table->boolean('is_featured')->default(0);
            $table->boolean('status')->default(1);
            $table->string('name', 255);
            $table->mediumText('description')->nullable();
            $table->mediumText('short_description')->nullable();
            $table->mediumText('specifications')->nullable()->comment('JSON {key:[],value:[]}');
            $table->string('sample_pdf', 255)->nullable();

            $table->decimal('regular_price', 28, 8)->default(0);
            $table->decimal('sale_price', 28, 8)->default(0);
            $table->boolean('sale_is_scheduled')->default(0);
            $table->dateTime('schedule_sale_start')->nullable();
            $table->dateTime('schedule_sale_end')->nullable();

            $table->unsignedBigInteger('tax_class')->default(0);
            $table->string('tax_status', 20)->nullable()->comment('taxable | none');
            $table->boolean('show_tax')->default(0);

            $table->string('sku', 80)->nullable()->index();
            $table->string('gtin', 80)->nullable();

            $table->boolean('inventory_type')->default(0)->comment('1 = track inventory');
            $table->integer('stock_quantity')->default(0)->comment('Company-wide total, kept in sync with branch stock');
            $table->integer('cross_sell_quantity')->default(0);
            $table->integer('up_sell_quantity')->default(0);
            $table->unsignedBigInteger('stock_unit_id')->default(0);
            $table->boolean('display_available')->default(0);
            $table->boolean('display_stock_quantity')->default(0);
            $table->integer('min_stock_quantity')->default(0);
            $table->tinyInteger('low_stock_activity')->default(0)->comment('0 nothing, 1 disable buy button, 2 unpublish');
            $table->integer('threshold_quantity')->default(0);
            $table->integer('min_cart_quantity')->default(1);
            $table->integer('max_cart_quantity')->default(1);
            $table->boolean('allow_backorder')->default(1);

            $table->decimal('weight', 10, 2)->default(0);
            $table->decimal('length', 10, 2)->default(0);
            $table->decimal('width', 10, 2)->default(0);
            $table->decimal('height', 10, 2)->default(0);
            $table->string('shipping_class', 40)->nullable();

            $table->string('product_url', 255)->nullable()->comment('External product link');
            $table->string('button_text', 80)->nullable();

            $table->string('slug', 255)->nullable()->index();
            $table->string('product_type', 40)->default('simple')->comment('simple | variable | grouped | external');

            $table->boolean('collection_one')->default(0);
            $table->boolean('collection_two')->default(0);
            $table->boolean('show_deals')->default(0);
            $table->boolean('limited_stock')->default(0);

            // Vehicle fitment — mirrors the source system exactly.
            $table->year('vehicle_year')->nullable()->index();
            $table->string('vehicle_model', 80)->nullable()->index();
            $table->string('vehicle_engine', 80)->nullable()->index();
            $table->string('vehicle_engine_type', 80)->nullable()->index();

            $table->string('meta_title', 255)->nullable();
            $table->string('meta_description', 255)->nullable();
            $table->text('meta_keywords')->nullable();

            $table->timestamps();

            $table->index(['status', 'product_type']);
            $table->index(['is_featured', 'status']);
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['category_id', 'product_id']);
        });

        Schema::create('product_attributes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->boolean('is_visible')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'attribute_id']);
        });

        Schema::create('attribute_value_product', function (Blueprint $table) {
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->cascadeOnDelete();

            $table->primary(['product_id', 'attribute_value_id']);
        });

        Schema::create('product_variations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->text('attribute_values')->nullable()->comment('JSON array of attribute_value ids');
            $table->decimal('regular_price', 28, 8)->default(0);
            $table->decimal('sale_price', 28, 8)->default(0);
            $table->boolean('sale_is_scheduled')->default(0);
            $table->dateTime('schedule_sale_start')->nullable();
            $table->dateTime('schedule_sale_end')->nullable();
            $table->string('sku', 80)->nullable();
            $table->string('gtin', 80)->nullable();
            $table->boolean('inventory_type')->default(0);
            $table->integer('min_cart_quantity')->default(0);
            $table->integer('max_cart_quantity')->default(0);
            $table->boolean('allow_backorder')->default(0);
            $table->integer('weight')->default(0);
            $table->integer('stock_quantity')->default(0);
            $table->integer('length')->default(0);
            $table->integer('width')->default(0);
            $table->integer('height')->default(0);
            $table->string('shipping_class', 40)->nullable();
            $table->unsignedBigInteger('stock_unit_id')->default(0);
            $table->boolean('display_available')->default(0);
            $table->boolean('display_stock_quantity')->default(0);
            $table->integer('min_stock_quantity')->default(0);
            $table->tinyInteger('low_stock_activity')->default(0);
            $table->integer('threshold_quantity')->default(0);
            $table->timestamps();
        });

        Schema::create('product_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path', 255)->nullable();
            $table->boolean('is_main')->default(0);
            $table->boolean('for_variant')->default(0);
            $table->unsignedBigInteger('variation_id')->default(0)->index();
            $table->string('video_link', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('product_groupeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('grouped_product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('product_up_sells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('up_sell_product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('product_cross_sells', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('cross_sell_product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('product_downloadable_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variation_id')->default(0);
            $table->string('file_path', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->text('review')->nullable();
            $table->unsignedTinyInteger('rating')->default(0);
            $table->boolean('is_viewed')->default(0);
            $table->tinyInteger('status')->default(0)->comment('0 pending, 1 approved, 2 rejected');
            $table->text('images')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
        });

        Schema::create('product_review_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_review_id')->constrained('product_reviews')->cascadeOnDelete();
            $table->unsignedBigInteger('admin_id')->nullable();
            $table->text('images')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_review_replies');
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('product_downloadable_files');
        Schema::dropIfExists('product_cross_sells');
        Schema::dropIfExists('product_up_sells');
        Schema::dropIfExists('product_groupeds');
        Schema::dropIfExists('product_media');
        Schema::dropIfExists('product_variations');
        Schema::dropIfExists('attribute_value_product');
        Schema::dropIfExists('product_attributes');
        Schema::dropIfExists('category_product');
        Schema::dropIfExists('products');
        Schema::dropIfExists('attribute_values');
        Schema::dropIfExists('attributes');
        Schema::dropIfExists('shipping_classes');
        Schema::dropIfExists('stock_units');
        Schema::dropIfExists('taxes');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
