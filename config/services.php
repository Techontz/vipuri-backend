<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    */

    'stripe' => [
        'secret' => env('STRIPE_SECRET_KEY'),
        'key' => env('STRIPE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID'),
        'client_secret' => env('PAYPAL_CLIENT_SECRET'),
        'mode' => env('PAYPAL_MODE', 'sandbox'),
    ],

    'flutterwave' => [
        'public' => env('FLUTTERWAVE_PUBLIC_KEY'),
        'secret' => env('FLUTTERWAVE_SECRET_KEY'),
        'encryption_key' => env('FLUTTERWAVE_ENCRYPTION_KEY'),
    ],

    'paystack' => [
        'public' => env('PAYSTACK_PUBLIC_KEY'),
        'secret' => env('PAYSTACK_SECRET_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS
    |--------------------------------------------------------------------------
    | Read through config, not env(), so it survives `php artisan config:cache`
    | in production — env() returns null once the config cache is built.
    */

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_TOKEN'),
        'from' => env('TWILIO_FROM'),
    ],


    /*
    |--------------------------------------------------------------------------
    | Stock photography
    |--------------------------------------------------------------------------
    |
    | Used only by `php artisan vipuri:catalog-images` to give seeded products
    | and categories real pictures. Both licences permit commercial use with no
    | attribution required, which is why the storefront carries no credit line.
    | Neither key is needed to run the shop.
    |
    */

    'pexels' => [
        'key' => env('PEXELS_API_KEY'),
    ],

    'unsplash' => [
        'key' => env('UNSPLASH_API_KEY'),
    ],

];
