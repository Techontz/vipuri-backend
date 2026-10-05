<?php

use App\Http\Controllers\Api\Account\SocialAuthController;
use App\Services\SocialLogin;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
| VIPURI is API-first: the storefront and admin panel are the Next.js app.
| The only web routes are a health/landing endpoint and public media.
*/

Route::get('/', fn () => response()->json([
    'name' => config('app.name'),
    'api' => url('/api/v1'),
    'frontend' => config('vipuri.frontend_url'),
    'status' => 'ok',
]));

/*
 * Social sign-in.
 *
 * Full page navigations, so they belong here rather than on the API routes.
 * The callback hands a Sanctum token back to the storefront.
 */
Route::controller(SocialAuthController::class)
    ->middleware('throttle:20,1')
    ->whereIn('provider', SocialLogin::PROVIDERS)
    ->group(function () {
        Route::get('/social-login/{provider}', 'redirect')->name('social.login');
        Route::get('/social-login/{provider}/callback', 'callback')->name('social.login.callback');
    });

/**
 * Public media.
 *
 * Serves the `public` disk. Paths are resolved through the filesystem
 * abstraction and validated against the disk root, so a crafted path cannot
 * escape into the application source.
 *
 * Directories belonging to a path key marked `private` are refused outright.
 * Those uploads are written to the `local` disk and so are not reachable here
 * anyway; the check also covers files written before the key became private,
 * which would otherwise still be downloadable without any ownership check.
 */
Route::get('/media/{path}', function (string $path) {
    $disk = Storage::disk('public');

    // Reject traversal outright rather than relying on realpath alone.
    if (str_contains($path, '..') || str_starts_with($path, '/')) {
        abort(404);
    }

    foreach (config('vipuri.file_path', []) as $entry) {
        if (($entry['private'] ?? false) && str_starts_with($path, trim($entry['path'], '/') . '/')) {
            abort(404);
        }
    }

    if (! $disk->exists($path)) {
        abort(404);
    }

    // Derive the root from the disk rather than assuming a path, so this stays
    // correct if the disk is reconfigured.
    $root = realpath($disk->path(''));
    $real = realpath($disk->path($path));

    if (! $real || ! $root || ! str_starts_with($real, $root)) {
        abort(404);
    }

    return response()->file($real, [
        'Cache-Control' => 'public, max-age=31536000, immutable',
    ]);
})->where('path', '.*')->name('media')
    // A file response needs no session, cookies or CSRF token. Starting a
    // session for every image wrote two cookies per file and serialised
    // concurrent image requests on the session store.
    ->withoutMiddleware([
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
    ]);
