<?php

namespace Tests\Feature;

use App\Constants\Roles;
use App\Constants\Status;
use App\Models\Extension;
use App\Models\GeneralSetting;
use App\Services\Captcha;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Captcha and social sign-in — the two protections carried over from the
 * source system's `Lib/Captcha.php` and `Lib/SocialLogin.php`.
 */
class CaptchaAndSocialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedFoundation();
    }

    /* -------------------------------- Captcha ------------------------------ */

    private function enableCustomCaptcha(string $key = 'test-captcha-key'): void
    {
        Extension::updateOrCreate(
            ['act' => 'custom-captcha'],
            [
                'name' => 'Custom Captcha',
                'status' => Status::ENABLE,
                'shortcode' => ['random_key' => ['title' => 'Random String', 'value' => $key]],
            ],
        );
    }

    /** Pull the digits back out of the SVG the API returned. */
    private function digitsFrom(string $svg): string
    {
        preg_match_all('/>(\d)<\/text>/', $svg, $matches);

        return implode('', $matches[1]);
    }

    public function test_no_captcha_is_issued_while_every_extension_is_disabled(): void
    {
        $response = $this->getJson('/api/v1/captcha')->assertOk();

        $this->assertNull($response->json('data.captcha.custom'));
        $this->assertNull($response->json('data.captcha.recaptcha'));
    }

    public function test_login_is_unaffected_when_no_captcha_is_enabled(): void
    {
        $customer = $this->makeCustomer();

        $this->postJson('/api/v1/auth/login', [
            'username' => $customer->username,
            'password' => 'Testing@2026',
        ])->assertOk()->assertJsonPath('remark', 'logged_in');
    }

    public function test_custom_captcha_challenge_is_issued_and_accepts_the_right_code(): void
    {
        $this->enableCustomCaptcha();
        $customer = $this->makeCustomer();

        $challenge = $this->getJson('/api/v1/captcha')->assertOk()->json('data.captcha.custom');

        $this->assertNotNull($challenge);

        $this->postJson('/api/v1/auth/login', [
            'username' => $customer->username,
            'password' => 'Testing@2026',
            'captcha' => $this->digitsFrom($challenge['svg']),
            'captcha_secret' => $challenge['secret'],
        ])->assertOk()->assertJsonPath('remark', 'logged_in');
    }

    public function test_login_is_refused_when_the_captcha_is_wrong_missing_or_forged(): void
    {
        $this->enableCustomCaptcha();
        $customer = $this->makeCustomer();

        $challenge = $this->getJson('/api/v1/captcha')->json('data.captcha.custom');
        $code = $this->digitsFrom($challenge['svg']);

        $attempts = [
            'wrong code' => ['captcha' => '000000', 'captcha_secret' => $challenge['secret']],
            'omitted entirely' => [],
            'forged secret' => ['captcha' => $code, 'captcha_secret' => str_repeat('a', 64)],
            'right code, wrong secret' => ['captcha' => $code, 'captcha_secret' => hash('sha256', $code)],
        ];

        foreach ($attempts as $label => $extra) {
            $this->postJson('/api/v1/auth/login', array_merge([
                'username' => $customer->username,
                'password' => 'Testing@2026',
            ], $extra))
                ->assertStatus(422)
                ->assertJsonPath('remark', 'captcha_failed', "Expected {$label} to be rejected");
        }
    }

    public function test_a_challenge_minted_under_one_key_does_not_verify_under_another(): void
    {
        $this->enableCustomCaptcha('first-key');
        $customer = $this->makeCustomer();

        $challenge = $this->getJson('/api/v1/captcha')->json('data.captcha.custom');
        $code = $this->digitsFrom($challenge['svg']);

        // An administrator rotates the key.
        $this->enableCustomCaptcha('second-key');

        $this->postJson('/api/v1/auth/login', [
            'username' => $customer->username,
            'password' => 'Testing@2026',
            'captcha' => $code,
            'captcha_secret' => $challenge['secret'],
        ])->assertStatus(422)->assertJsonPath('remark', 'captcha_failed');
    }

    public function test_captcha_guards_registration_contact_and_the_admin_panel(): void
    {
        $this->enableCustomCaptcha();
        $staff = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->postJson('/api/v1/auth/register', [
            'firstname' => 'Neema', 'lastname' => 'Kimaro',
            'username' => 'neema.kimaro', 'email' => 'neema@example.co.tz',
            'password' => 'Testing@2026', 'password_confirmation' => 'Testing@2026',
        ])->assertStatus(422)->assertJsonPath('remark', 'captcha_failed');

        $this->postJson('/api/v1/contact', [
            'name' => 'Neema', 'email' => 'neema@example.co.tz',
            'subject' => 'Fitment question', 'message' => 'Does this fit a Hilux?',
        ])->assertStatus(422)->assertJsonPath('remark', 'captcha_failed');

        $this->postJson('/api/v1/admin/auth/login', [
            'username' => $staff->username,
            'password' => 'Testing@2026',
        ])->assertStatus(422)->assertJsonPath('remark', 'captcha_failed');
    }

    public function test_recaptcha_fails_closed_when_google_rejects_the_token(): void
    {
        Extension::updateOrCreate(
            ['act' => 'google-recaptcha2'],
            [
                'name' => 'Google Recaptcha 2',
                'status' => Status::ENABLE,
                'shortcode' => [
                    'site_key' => ['title' => 'Site Key', 'value' => 'site'],
                    'secret_key' => ['title' => 'Secret Key', 'value' => 'secret'],
                ],
            ],
        );

        Http::fake(['www.google.com/*' => Http::response(['success' => false], 200)]);

        $customer = $this->makeCustomer();

        $this->postJson('/api/v1/auth/login', [
            'username' => $customer->username,
            'password' => 'Testing@2026',
            'g-recaptcha-response' => 'a-token-google-will-reject',
        ])->assertStatus(422)->assertJsonPath('remark', 'captcha_failed');
    }

    public function test_the_site_key_is_published_but_the_secret_key_never_is(): void
    {
        Extension::updateOrCreate(
            ['act' => 'google-recaptcha2'],
            [
                'name' => 'Google Recaptcha 2',
                'status' => Status::ENABLE,
                'shortcode' => [
                    'site_key' => ['title' => 'Site Key', 'value' => 'public-site-key'],
                    'secret_key' => ['title' => 'Secret Key', 'value' => 'private-secret-key'],
                ],
            ],
        );

        $response = $this->getJson('/api/v1/settings')->assertOk();

        $this->assertSame('public-site-key', $response->json('data.captcha.recaptcha.site_key'));
        $this->assertStringNotContainsString('private-secret-key', $response->getContent());
    }

    /* ------------------------------ Social login --------------------------- */

    private function configureGoogle(bool $status = true): void
    {
        $settings = GeneralSetting::current();
        $settings->socialite_credentials = [
            'google' => ['client_id' => 'google-id', 'client_secret' => 'google-secret', 'status' => $status],
        ];
        $settings->save();
        GeneralSetting::flush();
    }

    public function test_only_configured_and_enabled_providers_are_advertised(): void
    {
        $this->assertSame([], $this->getJson('/api/v1/settings')->json('data.social_logins'));

        $this->configureGoogle();

        $this->assertSame(['google'], $this->getJson('/api/v1/settings')->json('data.social_logins'));
    }

    public function test_a_provider_with_no_secret_is_never_advertised(): void
    {
        $settings = GeneralSetting::current();
        $settings->socialite_credentials = [
            'google' => ['client_id' => 'google-id', 'client_secret' => '', 'status' => true],
        ];
        $settings->save();
        GeneralSetting::flush();

        $this->assertSame([], $this->getJson('/api/v1/settings')->json('data.social_logins'));
    }

    public function test_provider_secrets_are_never_sent_to_the_browser(): void
    {
        $this->configureGoogle();

        $content = $this->getJson('/api/v1/settings')->getContent();

        $this->assertStringNotContainsString('google-secret', $content);
        $this->assertStringNotContainsString('google-id', $content);
    }

    public function test_redirect_sends_the_visitor_to_the_provider(): void
    {
        $this->configureGoogle();

        $this->get('/social-login/google')
            ->assertRedirectContains('accounts.google.com')
            ->assertRedirectContains('client_id=google-id');
    }

    public function test_a_disabled_provider_bounces_back_with_an_error(): void
    {
        $this->configureGoogle(status: false);

        $this->get('/social-login/google')
            ->assertRedirectContains('/social-callback')
            ->assertRedirectContains('error=');
    }

    public function test_an_unknown_provider_is_not_routed_at_all(): void
    {
        $this->get('/social-login/myspace')->assertNotFound();
    }

    /* ------------------------- Admin configuration ------------------------- */

    public function test_a_provider_cannot_be_enabled_without_both_credentials(): void
    {
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson('/api/v1/admin/settings/social-logins/google', [
                'client_id' => 'only-an-id',
                'status' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('remark', 'incomplete_credentials');

        $this->assertSame([], app(\App\Services\SocialLogin::class)->enabled());
    }

    public function test_saving_with_a_blank_secret_keeps_the_stored_one(): void
    {
        $this->configureGoogle();
        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson('/api/v1/admin/settings/social-logins/google', [
                'client_id' => 'a-new-id',
                'client_secret' => '',
                'status' => true,
            ])
            ->assertOk();

        GeneralSetting::flush();
        $stored = GeneralSetting::current()->socialite_credentials->google;

        $this->assertSame('a-new-id', $stored->client_id);
        $this->assertSame('google-secret', $stored->client_secret);
    }

    public function test_an_extension_cannot_be_enabled_with_an_empty_field(): void
    {
        $extension = Extension::updateOrCreate(
            ['act' => 'google-recaptcha2'],
            [
                'name' => 'Google Recaptcha 2',
                'status' => Status::DISABLE,
                'shortcode' => [
                    'site_key' => ['title' => 'Site Key', 'value' => ''],
                    'secret_key' => ['title' => 'Secret Key', 'value' => ''],
                ],
            ],
        );

        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson("/api/v1/admin/extensions/{$extension->id}", [
                'shortcode' => ['site_key' => 'a-site-key', 'secret_key' => ''],
                'status' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('remark', 'incomplete_extension');

        $this->assertFalse((bool) $extension->fresh()->status);
    }

    public function test_extension_secrets_are_not_echoed_back_to_the_admin_panel(): void
    {
        Extension::updateOrCreate(
            ['act' => 'google-recaptcha2'],
            [
                'name' => 'Google Recaptcha 2',
                'status' => Status::ENABLE,
                'shortcode' => [
                    'site_key' => ['title' => 'Site Key', 'value' => 'a-site-key'],
                    'secret_key' => ['title' => 'Secret Key', 'value' => 'the-real-secret'],
                ],
            ],
        );

        $admin = $this->makeStaff(Roles::SUPER_ADMIN);

        $response = $this->withHeaders($this->adminHeaders($admin))
            ->getJson('/api/v1/admin/extensions')
            ->assertOk();

        $this->assertStringNotContainsString('the-real-secret', $response->getContent());
        $this->assertStringContainsString('a-site-key', $response->getContent());

        $fields = collect($response->json('data.extensions'))
            ->firstWhere('act', 'google-recaptcha2')['fields'];
        $fields = collect($fields)->keyBy('name');
        $this->assertTrue($fields['secret_key']['is_set']);
        $this->assertSame('', $fields['secret_key']['value']);
    }

    public function test_the_challenge_svg_never_repeats_the_same_code(): void
    {
        $this->enableCustomCaptcha();
        $captcha = app(Captcha::class);

        $codes = collect(range(1, 12))
            ->map(fn () => $this->digitsFrom($captcha->challenge()['svg']))
            ->unique();

        $this->assertGreaterThan(6, $codes->count(), 'Challenge codes look predictable');
    }
}
