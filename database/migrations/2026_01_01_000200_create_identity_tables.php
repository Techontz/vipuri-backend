<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two authentication guards, exactly as in the source system:
 *
 *  - `users`  : storefront customers
 *  - `admins` : VIPURI staff (super admin, branch manager, branch worker)
 *
 * Staff carry a branch_id; a null branch_id means company-wide scope
 * (super admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('firstname', 80)->nullable();
            $table->string('lastname', 80)->nullable();
            $table->string('username', 80)->unique();
            $table->string('email', 191)->unique();
            $table->string('dial_code', 10)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->string('password');
            $table->string('image', 255)->nullable();
            $table->string('country_name', 191)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('city', 191)->nullable();
            $table->string('state', 191)->nullable();
            $table->string('zip', 40)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('preferred_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->boolean('status')->default(1);
            $table->boolean('ev')->default(0)->comment('Email verified');
            $table->boolean('sv')->default(0)->comment('SMS verified');
            $table->boolean('profile_complete')->default(0);
            $table->string('ver_code', 40)->nullable();
            $table->dateTime('ver_code_send_at')->nullable();
            $table->string('ban_reason', 255)->nullable();
            $table->rememberToken();
            $table->string('provider', 40)->nullable();
            $table->string('provider_id', 191)->nullable();
            $table->timestamps();

            $table->index(['status', 'ev']);
            $table->index('mobile');
        });

        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name', 120);
            $table->string('email', 191)->unique();
            $table->string('username', 80)->unique();
            $table->string('dial_code', 10)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('image', 255)->nullable();
            $table->string('password');
            $table->boolean('status')->default(1);
            $table->string('ban_reason', 255)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 60)->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('password_resets', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->index();
            $table->string('token', 80);
            $table->boolean('status')->default(1);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('admin_password_resets', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->index();
            $table->string('token', 80);
            $table->boolean('status')->default(1);
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('user_logins', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('guard', 20)->default('user')->comment('user | admin');
            $table->string('user_ip', 60)->nullable();
            $table->string('city', 80)->nullable();
            $table->string('country', 80)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('longitude', 40)->nullable();
            $table->string('latitude', 40)->nullable();
            $table->string('browser', 80)->nullable();
            $table->string('os', 80)->nullable();
            $table->timestamps();
        });

        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('firstname', 80)->nullable();
            $table->string('lastname', 80)->nullable();
            $table->string('email', 191);
            $table->string('dial_code', 10)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->text('address')->nullable();
            $table->string('state', 191)->nullable();
            $table->string('city', 191)->nullable();
            $table->string('zip', 40)->nullable();
            $table->string('country_name', 191)->nullable();
            $table->text('session_id')->nullable();
            $table->timestamps();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->unsignedBigInteger('guest_id')->default(0)->index();
            $table->string('title', 120);
            $table->string('firstname', 80);
            $table->string('lastname', 80);
            $table->string('dial_code', 10)->nullable();
            $table->string('mobile', 40)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('city', 191)->nullable();
            $table->string('state', 191)->nullable();
            $table->string('zip', 40)->nullable();
            $table->string('country_name', 191)->nullable();
            $table->string('country_code', 10)->nullable();
            $table->string('address', 255)->nullable();
            $table->boolean('is_default')->default(0);
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('actor_type', 20)->default('admin')->comment('admin | user | system');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->string('event', 80)->index()->comment('created | updated | deleted | login | ...');
            $table->string('auditable_type', 120)->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 60)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('guests');
        Schema::dropIfExists('user_logins');
        Schema::dropIfExists('admin_password_resets');
        Schema::dropIfExists('password_resets');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('admins');
        Schema::dropIfExists('users');
    }
};
