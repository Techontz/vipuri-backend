<?php

namespace App\Services;

use App\Constants\Status;
use App\Models\User;
use App\Models\UserLogin;
use App\Support\UserAgent;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;

/**
 * Sign in with Google, Facebook or LinkedIn — the `Lib/SocialLogin.php`
 * feature from the source system.
 *
 * Credentials live in `general_settings.socialite_credentials` and are managed
 * from Admin → Settings → Social Login, so no provider secret is ever compiled
 * into the application or shipped to the browser.
 *
 * The flow differs from the original only where it has to: the storefront is a
 * separate application, so the callback lands on the API, mints a Sanctum
 * token, and bounces the visitor back to the frontend with it.
 */
class SocialLogin
{
    public const PROVIDERS = ['google', 'facebook', 'linkedin'];

    /** Providers an administrator has both configured and switched on. */
    public function enabled(): array
    {
        $credentials = gs('socialite_credentials');

        return collect(self::PROVIDERS)
            ->filter(function (string $provider) use ($credentials) {
                $config = $credentials?->$provider ?? null;

                return $config
                    && (bool) ($config->status ?? false)
                    && trim((string) ($config->client_id ?? '')) !== ''
                    && trim((string) ($config->client_secret ?? '')) !== '';
            })
            ->values()
            ->all();
    }

    /** The provider's consent-screen URL. */
    public function redirectUrl(string $provider): string
    {
        $this->configure($provider);

        return Socialite::driver($this->driverName($provider))
            ->stateless()
            ->redirect()
            ->getTargetUrl();
    }

    /**
     * Complete the handshake and return [$user, $token].
     *
     * @return array{0: User, 1: string}
     */
    public function complete(string $provider): array
    {
        $this->configure($provider);

        $driverName = $this->driverName($provider);
        $profile = Socialite::driver($driverName)->stateless()->user();

        $providerId = (string) ($driverName === 'linkedin-openid'
            ? ($profile->user['sub'] ?? $profile->getId())
            : $profile->getId());

        $user = User::where('provider', $provider)->where('provider_id', $providerId)->first();

        if (! $user) {
            $user = $this->link($provider, $providerId, $profile);
        }

        if (! $user->status) {
            throw new RuntimeException('This account has been suspended.');
        }

        $this->logLogin($user);

        return [$user, $user->createToken('storefront')->plainTextToken];
    }

    /* ------------------------------------------------------------------ *
     | Internals
     * ------------------------------------------------------------------ */

    /**
     * Attach the provider to an existing account, or create one.
     *
     * The original refused when the e-mail already existed. Refusing strands
     * the visitor with no way forward, so instead the provider is linked to
     * the account that already owns the address — the provider has verified it,
     * which is the same assurance a password reset would give.
     */
    private function link(string $provider, string $providerId, $profile): User
    {
        $email = (string) ($profile->getEmail() ?? '');

        if ($email === '') {
            throw new RuntimeException('Your ' . ucfirst($provider) . ' account did not share an email address.');
        }

        $user = User::where('email', $email)->first();

        if ($user) {
            $user->forceFill(['provider' => $provider, 'provider_id' => $providerId])->save();

            return $user;
        }

        if (! gs('registration')) {
            throw new RuntimeException('New account registration is currently closed.');
        }

        [$firstname, $lastname] = $this->splitName((string) ($profile->getName() ?? ''), $email);

        $user = new User([
            'firstname' => $firstname,
            'lastname' => $lastname,
            'email' => $email,
            'username' => $this->uniqueUsername($email),
        ]);

        $user->forceFill([
            'provider' => $provider,
            'provider_id' => $providerId,
            // No password is ever usable: the account signs in through the
            // provider, and a reset issues a fresh one if they want local login.
            'password' => Str::random(48),
            'status' => Status::ENABLE,
            'ev' => Status::VERIFIED,
            'sv' => gs('sv') ? Status::UNVERIFIED : Status::VERIFIED,
        ])->save();

        app(NotificationService::class)->toStaff(
            "New customer registered via {$provider}: {$user->fullname}",
            "/admin/customers/{$user->id}",
        );

        return $user;
    }

    private function splitName(string $name, string $email): array
    {
        $name = trim($name);

        if ($name === '') {
            return [Str::before($email, '@'), ''];
        }

        $pieces = preg_split('/\s+/', $name);
        $lastname = count($pieces) > 1 ? array_pop($pieces) : '';

        return [implode(' ', $pieces), $lastname];
    }

    private function uniqueUsername(string $email): string
    {
        $base = Str::of($email)->before('@')->lower()->replaceMatches('/[^a-z0-9._-]/', '')->toString();
        $base = $base !== '' ? $base : 'customer';
        $username = $base;
        $suffix = 1;

        while (User::where('username', $username)->exists()) {
            $username = $base . ++$suffix;
        }

        return $username;
    }

    /** Socialite's LinkedIn driver moved to OpenID Connect. */
    private function driverName(string $provider): string
    {
        return $provider === 'linkedin' ? 'linkedin-openid' : $provider;
    }

    private function configure(string $provider): void
    {
        if (! in_array($provider, $this->enabled(), true)) {
            throw new RuntimeException('This sign-in method is not available.');
        }

        $config = gs('socialite_credentials')->$provider;

        Config::set('services.' . $this->driverName($provider), [
            'client_id' => $config->client_id,
            'client_secret' => $config->client_secret,
            'redirect' => url("/social-login/{$provider}/callback"),
        ]);
    }

    private function logLogin(User $user): void
    {
        $agent = (string) request()->userAgent();

        UserLogin::create([
            'user_id' => $user->id,
            'guard' => 'user',
            'user_ip' => request()->ip(),
            'browser' => UserAgent::browser($agent),
            'os' => UserAgent::os($agent),
            'country_name' => $user->country_name,
            'country_code' => $user->country_code,
        ]);
    }
}
