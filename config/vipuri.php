<?php

use App\Constants\Status;

return [
    /*
    |--------------------------------------------------------------------------
    | Storefront URL
    |--------------------------------------------------------------------------
    | The Next.js application. Used for CORS, e-mail links and gateway returns.
    */
    'frontend_url' => rtrim(env('FRONTEND_URL', 'http://127.0.0.1:3000'), '/'),

    /*
    |--------------------------------------------------------------------------
    | Force HTTPS
    |--------------------------------------------------------------------------
    | Read here rather than with env() at runtime, which returns null once
    | `php artisan config:cache` has run — as it should have, in production.
    */
    'force_ssl' => (bool) env('FORCE_HTTPS', false),

    /*
    |--------------------------------------------------------------------------
    | API rate limits (requests per minute)
    |--------------------------------------------------------------------------
    | Staff need the widest allowance because one admin screen legitimately
    | issues several requests. Anonymous traffic is kept tight. `ipn` covers
    | gateway webhooks, which are machine traffic rather than browser traffic.
    */
    'rate_limit' => [
        'admin' => (int) env('RATE_LIMIT_ADMIN', 600),
        'user' => (int) env('RATE_LIMIT_USER', 240),
        'guest' => (int) env('RATE_LIMIT_GUEST', 120),
        'ipn' => (int) env('RATE_LIMIT_IPN', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    | VIPURI trades exclusively in Tanzanian Shillings. These act as the
    | fallback when the general_settings row has not been seeded yet.
    */
    'currency' => [
        'text' => env('CURRENCY_TEXT', 'TZS'),
        'symbol' => env('CURRENCY_SYMBOL', 'TSh'),
        'decimals' => 0, // TZS is not sub-divided in practice
    ],

    /*
    |--------------------------------------------------------------------------
    | File storage
    |--------------------------------------------------------------------------
    | Paths are relative to the `public` disk; sizes drive the resizer.
    |
    | A key marked `private` is stored on the `local` disk instead, outside the
    | web root and outside anything the /media route will serve. Support
    | attachments carry receipts and identity documents, so an ownership check
    | on the download endpoint is only meaningful if the file has no public URL.
    */
    'file_path' => [
        'product' => ['path' => 'assets/images/product', 'size' => '800x800', 'thumb' => '350x350'],
        'category' => ['path' => 'assets/images/category', 'size' => '400x400', 'thumb' => '100x100'],
        'brand' => ['path' => 'assets/images/brand', 'size' => '270x160'],
        'branch' => ['path' => 'assets/images/branch', 'size' => '800x500'],
        'userProfile' => ['path' => 'assets/images/user/profile', 'size' => '350x300'],
        'adminProfile' => ['path' => 'assets/images/admin/profile', 'size' => '400x400'],
        'verify' => ['path' => 'assets/images/verify', 'size' => '800x800'],
        /*
         * `preserve_format` opts a key out of the WebP conversion in
         * FileManager. This one holds the favicon, and browser support for a
         * WebP favicon is inconsistent enough that a missing tab icon is a
         * worse trade than a slightly larger file.
         */
        'logoIcon' => ['path' => 'assets/images/logo_icon', 'preserve_format' => true],
        'maintenance' => ['path' => 'assets/images/maintenance', 'size' => '600x400'],
        'seo' => ['path' => 'assets/images/seo', 'size' => '600x315'],
        /*
         * No fixed size: CMS images range from 45px icons to 1920x700 hero
         * banners, and cropping every one to 800x600 cut them apart.
         */
        'frontend' => ['path' => 'assets/images/frontend'],
        'ticket' => ['path' => 'assets/images/support', 'size' => '400x400'],
        'review' => ['path' => 'assets/images/review', 'size' => '400x400'],
        'offer' => ['path' => 'assets/images/offer', 'size' => '600x400'],
        'campaign' => ['path' => 'assets/images/campaign', 'size' => '1000x400'],
        'gateway' => ['path' => 'assets/images/gateway', 'size' => '400x400'],
        'shipping' => ['path' => 'assets/images/shipping', 'size' => '400x400'],
        'attachment' => ['path' => 'assets/attachments', 'private' => true],
        'download' => ['path' => 'assets/downloads'],
        'language' => ['path' => 'assets/images/lang', 'size' => '64x64'],
        'push' => ['path' => 'assets/images/push'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ordering
    |--------------------------------------------------------------------------
    */
    'order' => [
        'number_prefix' => env('ORDER_NUMBER_PREFIX', 'VP'),
        /* Statuses a branch worker may set. Managers/super admins get all. */
        'worker_allowed_statuses' => [
            Status::ORDER_PROCESSING,
            Status::ORDER_DISPATCHED,
            Status::ORDER_DELIVERED,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'default_engine' => (int) env('AI_DEFAULT_ENGINE', Status::OPENAI_MODEL),
        'openai' => [
            'key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_API_MODEL', 'gpt-4o-mini'),
            'endpoint' => 'https://api.openai.com/v1/chat/completions',
        ],
        'gemini' => [
            'key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_API_MODEL', 'gemini-2.5-flash'),
            'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/models',
        ],
        'timeout' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    | `drivers` maps a gateway alias to the class that actually talks to the
    | provider. Aliases absent from this map are manual gateways (bank deposit,
    | mobile-money-on-file) that are confirmed by an administrator.
    */
    'payment' => [
        'drivers' => [
            'Stripe' => \App\Services\Payment\Drivers\StripeDriver::class,
            'Paypal' => \App\Services\Payment\Drivers\PaypalDriver::class,
            'Flutterwave' => \App\Services\Payment\Drivers\FlutterwaveDriver::class,
            'Paystack' => \App\Services\Payment\Drivers\PaystackDriver::class,
        ],
    ],
];
