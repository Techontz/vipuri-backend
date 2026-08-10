<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * VIPURI is a single-company, multi-branch business.
 *
 * The `companies` table holds exactly one row (VIPURI itself) so that every
 * branch, staff member and operational record can be anchored to a company id
 * without hard-coding it, and `branches` holds the physical outlets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 191);
            $table->string('legal_name', 191)->nullable();
            $table->string('slug', 191)->unique();
            $table->string('email', 191)->nullable();
            $table->string('phone', 60)->nullable();
            $table->string('tin', 60)->nullable()->comment('Tanzania Revenue Authority TIN');
            $table->string('vrn', 60)->nullable()->comment('VAT registration number');
            $table->string('logo', 255)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 191)->nullable();
            $table->string('region', 191)->nullable();
            $table->string('country_name', 191)->default('Tanzania');
            $table->string('country_code', 10)->default('TZ');
            $table->string('currency_text', 10)->default('TZS');
            $table->string('currency_symbol', 10)->default('TSh');
            $table->boolean('status')->default(1);
            $table->timestamps();
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name', 191);
            $table->string('code', 40)->unique()->comment('Short branch code, e.g. DSM-01');
            $table->string('slug', 191)->unique();
            $table->string('email', 191)->nullable();
            $table->string('dial_code', 10)->nullable();
            $table->string('phone', 60)->nullable();
            $table->text('address')->nullable();
            $table->string('city', 191)->nullable();
            $table->string('region', 191)->nullable();
            $table->string('postal_code', 40)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('image', 255)->nullable();
            $table->boolean('is_default')->default(0)->comment('Fallback fulfilment branch');
            $table->boolean('is_pickup_point')->default(1);
            $table->boolean('status')->default(1);
            $table->text('opening_hours')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
    }
};
