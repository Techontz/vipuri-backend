<?php

/*
|--------------------------------------------------------------------------
| CORS
|--------------------------------------------------------------------------
| The Next.js app is the only browser client. FRONTEND_URL is the canonical
| origin; ADDITIONAL_CORS_ORIGINS accepts a comma-separated list for staging
| or preview deployments. In `local` the usual localhost ports are added so a
| developer can run the frontend on any port without editing config.
*/

$origins = array_filter(array_map('trim', array_merge(
    [env('FRONTEND_URL', 'http://127.0.0.1:3000')],
    explode(',', (string) env('ADDITIONAL_CORS_ORIGINS', '')),
)));

$patterns = [];

if (env('APP_ENV', 'production') === 'local') {
    $patterns[] = '#^http://(localhost|127\.0\.0\.1)(:\d+)?$#';
}

return [

    'paths' => ['api/*', 'media/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_unique($origins)),

    'allowed_origins_patterns' => $patterns,

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    'supports_credentials' => true,

];
