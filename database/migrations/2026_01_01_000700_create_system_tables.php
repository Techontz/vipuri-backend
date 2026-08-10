<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('general_settings', function (Blueprint $table) {
            $table->id();
            $table->string('site_name', 120)->default('VIPURI');
            $table->string('cur_text', 20)->default('TZS');
            $table->string('cur_sym', 20)->default('TSh');
            $table->string('email_from', 191)->nullable();
            $table->string('email_from_name', 191)->nullable();
            $table->text('email_template')->nullable();
            $table->string('sms_template', 255)->nullable();
            $table->string('sms_from', 191)->nullable();
            $table->string('push_title', 255)->nullable();
            $table->string('push_template', 255)->nullable();
            $table->string('base_color', 20)->nullable();
            $table->string('secondary_color', 20)->nullable();
            $table->text('mail_config')->nullable();
            $table->text('sms_config')->nullable();
            $table->text('firebase_config')->nullable();
            $table->text('global_shortcodes')->nullable();
            $table->boolean('ev')->default(0)->comment('Email verification');
            $table->boolean('en')->default(1)->comment('Email notification');
            $table->boolean('sv')->default(0)->comment('SMS verification');
            $table->boolean('sn')->default(0)->comment('SMS notification');
            $table->boolean('pn')->default(0)->comment('Push notification');
            $table->boolean('force_ssl')->default(0);
            $table->boolean('in_app_payment')->default(1);
            $table->boolean('has_cod')->default(1);
            $table->boolean('maintenance_mode')->default(0);
            $table->boolean('secure_password')->default(0);
            $table->boolean('agree')->default(0);
            $table->boolean('multi_language')->default(1);
            $table->boolean('registration')->default(1);
            $table->string('active_template', 40)->default('basic');
            $table->text('socialite_credentials')->nullable();
            $table->integer('paginate_number')->default(12);
            $table->tinyInteger('currency_format')->default(1)->comment('1 both, 2 text, 3 symbol');
            $table->string('order_number_prefix', 20)->default('VP');
            $table->tinyInteger('default_engine')->default(2)->comment('1 gemini, 2 openai');
            $table->text('gemini_api_key')->nullable();
            $table->text('openai_api_key')->nullable();
            $table->string('openai_api_model', 80)->nullable();
            $table->boolean('ai_review_summary')->default(1);
            $table->boolean('ai_product_chat')->default(1);
            $table->text('config_progress')->nullable();
            $table->timestamps();
        });

        Schema::create('frontends', function (Blueprint $table) {
            $table->id();
            $table->string('data_keys', 120)->nullable()->index();
            $table->longText('data_values')->nullable();
            $table->longText('seo_content')->nullable();
            $table->string('tempname', 40)->default('basic');
            $table->string('slug', 191)->nullable();
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->nullable();
            $table->string('slug', 120)->nullable()->index();
            $table->string('tempname', 40)->default('basic');
            $table->text('secs')->nullable();
            $table->text('seo_content')->nullable();
            $table->boolean('is_default')->default(0);
            $table->timestamps();
        });

        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80)->nullable();
            $table->string('code', 20)->nullable();
            $table->boolean('is_default')->default(0);
            $table->string('image', 80)->nullable();
            $table->timestamps();
        });

        Schema::create('extensions', function (Blueprint $table) {
            $table->id();
            $table->string('act', 80)->nullable();
            $table->string('name', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('image', 255)->nullable();
            $table->text('script')->nullable();
            $table->text('shortcode')->nullable();
            $table->text('support')->nullable();
            $table->boolean('status')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('act', 80)->nullable()->index();
            $table->string('name', 120)->nullable();
            $table->string('subject', 255)->nullable();
            $table->string('push_title', 255)->nullable();
            $table->text('email_body')->nullable();
            $table->text('sms_body')->nullable();
            $table->text('push_body')->nullable();
            $table->text('shortcodes')->nullable();
            $table->boolean('email_status')->default(1);
            $table->string('email_sent_from_name', 120)->nullable();
            $table->string('email_sent_from_address', 191)->nullable();
            $table->boolean('sms_status')->default(1);
            $table->string('sms_sent_from', 80)->nullable();
            $table->boolean('push_status')->default(0);
            $table->timestamps();
        });

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('sender', 80)->nullable();
            $table->string('sent_from', 191)->nullable();
            $table->string('sent_to', 191)->nullable();
            $table->string('subject', 255)->nullable();
            $table->text('message')->nullable();
            $table->string('notification_type', 40)->nullable();
            $table->string('image', 255)->nullable();
            $table->boolean('user_read')->default(0);
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('title', 255)->nullable();
            $table->string('click_url', 255)->nullable();
            $table->boolean('is_read')->default(0);
            $table->timestamps();
        });

        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete()
                ->comment('null = visible to super admin only');
            $table->unsignedBigInteger('user_id')->default(0);
            $table->unsignedBigInteger('guest_id')->default(0);
            $table->string('title', 255)->nullable();
            $table->boolean('is_read')->default(0);
            $table->text('click_url')->nullable();
            $table->timestamps();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->boolean('is_app')->default(0);
            $table->text('token')->nullable();
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(0)->index();
            $table->string('name', 120)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('ticket', 40)->nullable()->index();
            $table->string('subject', 255)->nullable();
            $table->tinyInteger('status')->default(0)
                ->comment('0 open, 1 answered, 2 replied, 3 closed');
            $table->tinyInteger('priority')->default(1)->comment('1 low, 2 medium, 3 high');
            $table->dateTime('last_reply')->nullable();
            $table->timestamps();
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->unsignedBigInteger('admin_id')->default(0);
            $table->longText('message')->nullable();
            $table->timestamps();
        });

        Schema::create('support_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->string('attachment', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('admin_notifications');
        Schema::dropIfExists('user_notifications');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('extensions');
        Schema::dropIfExists('languages');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('frontends');
        Schema::dropIfExists('general_settings');
    }
};
