<?php

namespace App\Http\Controllers\Api\Account;

use App\Http\Controllers\Controller;
use App\Services\SocialLogin;
use Illuminate\Http\RedirectResponse;
use Throwable;

/**
 * OAuth sign-in for customers.
 *
 * Two steps, both browser-facing:
 *
 *  1. `/social-login/{provider}` sends the visitor to the provider.
 *  2. `/social-login/{provider}/callback` completes the handshake, then
 *     redirects to the storefront's own callback page carrying either a token
 *     or an error message. The token is single-use in the sense that it is
 *     immediately stored by the frontend and the URL is replaced.
 *
 * These live on the web routes, not the API ones: a provider redirect is a
 * full page navigation, not an XHR.
 */
class SocialAuthController extends Controller
{
    public function __construct(private readonly SocialLogin $social) {}

    public function redirect(string $provider): RedirectResponse
    {
        try {
            return redirect()->away($this->social->redirectUrl($provider));
        } catch (Throwable $e) {
            return $this->back(error: $e->getMessage());
        }
    }

    public function callback(string $provider): RedirectResponse
    {
        try {
            [, $token] = $this->social->complete($provider);

            return $this->back(token: $token);
        } catch (Throwable $e) {
            return $this->back(error: $e->getMessage());
        }
    }

    /** Hand control back to the storefront. */
    private function back(?string $token = null, ?string $error = null): RedirectResponse
    {
        $query = $token
            ? ['token' => $token]
            : ['error' => $error ?: 'Sign-in failed. Please try again.'];

        return redirect()->away(
            rtrim(config('vipuri.frontend_url'), '/') . '/social-callback?' . http_build_query($query)
        );
    }
}
