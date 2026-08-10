<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class GeneralSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'mail_config' => 'object',
            'sms_config' => 'object',
            'firebase_config' => 'object',
            'global_shortcodes' => 'object',
            'socialite_credentials' => 'object',
            'config_progress' => 'object',
            'ev' => 'boolean',
            'en' => 'boolean',
            'sv' => 'boolean',
            'sn' => 'boolean',
            'pn' => 'boolean',
            'force_ssl' => 'boolean',
            'in_app_payment' => 'boolean',
            'has_cod' => 'boolean',
            'maintenance_mode' => 'boolean',
            'secure_password' => 'boolean',
            'agree' => 'boolean',
            'multi_language' => 'boolean',
            'registration' => 'boolean',
            'ai_review_summary' => 'boolean',
            'ai_product_chat' => 'boolean',
            'commission_enabled' => 'boolean',
            'commission_rate' => 'float',
        ];
    }

    /** Per-request instance. Never persisted to the cache store: a serialised
     *  model goes stale the moment the schema or the row changes. */
    private static ?self $instance = null;

    protected static function booted(): void
    {
        static::saved(function () {
            self::$instance = null;
            Cache::forget('GeneralSetting');
        });
    }

    public static function current(): self
    {
        return self::$instance ??= static::query()->firstOrFail();
    }

    public static function flush(): void
    {
        self::$instance = null;
        Cache::forget('GeneralSetting');
    }
}
