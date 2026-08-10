<?php

namespace App\Services;

use App\Constants\Status;
use App\Models\Extension;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Captcha for the public forms, mirroring `Lib/Captcha.php` in the source.
 *
 * Two independent challenges, each an extension that an administrator enables:
 *
 *  - `google-recaptcha2` — the widget is rendered by the browser and the
 *    response token is verified against Google.
 *  - `custom-captcha` — VIPURI draws six digits and returns them alongside an
 *    HMAC of the code. The digits never leave as plain text the server will
 *    trust: verification re-computes the HMAC from what the visitor typed.
 *
 * The original rendered both inside a Blade form. Since the storefront is now a
 * separate application, `config()` describes the active challenge and the
 * frontend renders it.
 */
class Captcha
{
    /** What the storefront needs to render the active challenges. */
    public function config(): array
    {
        $recaptcha = $this->extension('google-recaptcha2');
        $custom = $this->extension('custom-captcha');

        return [
            'recaptcha' => $recaptcha ? [
                'site_key' => (string) ($recaptcha->shortcode?->site_key?->value ?? ''),
            ] : null,
            'custom' => $custom ? $this->challenge($custom) : null,
        ];
    }

    /**
     * Issue a fresh custom-captcha challenge.
     *
     * Returns the six digits as an inline SVG plus the HMAC the visitor must
     * send back. The key lives in the extension record, so a challenge minted
     * by one request verifies on any other — no session required.
     */
    public function challenge(?Extension $extension = null): ?array
    {
        $extension ??= $this->extension('custom-captcha');

        if (! $extension) {
            return null;
        }

        $code = (string) random_int(100000, 999999);

        return [
            'svg' => $this->draw($code),
            'secret' => $this->sign($code, $extension),
        ];
    }

    /**
     * Verify whichever challenges are enabled.
     *
     * Returns null when the request passes, or the message to fail with.
     */
    public function verify(Request $request): ?string
    {
        if ($recaptcha = $this->extension('google-recaptcha2')) {
            $secret = (string) ($recaptcha->shortcode?->secret_key?->value ?? '');
            $token = (string) $request->input('g-recaptcha-response', '');

            if ($secret === '') {
                // Enabled but unconfigured: fail closed rather than wave
                // everybody through on a form the operator meant to protect.
                return 'Captcha is not configured correctly. Please contact support.';
            }

            if ($token === '' || ! $this->verifyWithGoogle($secret, $token, $request->ip())) {
                return 'Please complete the captcha.';
            }
        }

        if ($custom = $this->extension('custom-captcha')) {
            $answer = trim((string) $request->input('captcha', ''));
            $secret = (string) $request->input('captcha_secret', '');

            if ($answer === '' || $secret === '' || ! hash_equals($this->sign($answer, $custom), $secret)) {
                return 'The captcha code is incorrect.';
            }
        }

        return null;
    }

    /* ------------------------------------------------------------------ *
     | Internals
     * ------------------------------------------------------------------ */

    private function extension(string $act): ?Extension
    {
        return Extension::where('act', $act)->where('status', Status::ENABLE)->first();
    }

    private function sign(string $code, Extension $extension): string
    {
        $key = (string) ($extension->shortcode?->random_key?->value ?? '');

        // An unkeyed HMAC would be forgeable by anyone who read this file.
        if ($key === '') {
            $key = config('app.key');
        }

        return hash_hmac('sha256', $code, $key);
    }

    private function verifyWithGoogle(string $secret, string $token, ?string $ip): bool
    {
        try {
            $response = Http::asForm()
                ->timeout(10)
                ->post('https://www.google.com/recaptcha/api/siteverify', [
                    'secret' => $secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]);

            return (bool) ($response->json('success') ?? false);
        } catch (\Throwable) {
            // A Google outage must not lock every visitor out of the site.
            return false;
        }
    }

    /**
     * Draw the code the way the original did: rotated digits in the brand
     * colour on a dark plate, unselectable so it cannot be copied.
     */
    private function draw(string $code): string
    {
        $textColor = '#' . ltrim((string) (gs('base_color') ?? 'FF7A00'), '#');
        $width = 260;
        $height = 46;
        $digits = str_split($code);
        $step = $width / (count($digits) + 1);

        $glyphs = '';

        foreach ($digits as $index => $digit) {
            $x = $step * ($index + 1);
            $y = $height / 2 + random_int(-3, 3);
            $angle = random_int(-25, 25);

            $glyphs .= sprintf(
                '<text x="%.1f" y="%.1f" transform="rotate(%d %.1f %.1f)" fill="%s" font-size="24" '
                . 'font-family="Georgia, serif" font-weight="bold" text-anchor="middle" '
                . 'dominant-baseline="central">%s</text>',
                $x, $y, $angle, $x, $y, $textColor, $digit,
            );
        }

        $noise = '';

        for ($i = 0; $i < 5; $i++) {
            $noise .= sprintf(
                '<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="%s" stroke-opacity="0.35" stroke-width="1"/>',
                random_int(0, $width), random_int(0, $height),
                random_int(0, $width), random_int(0, $height),
                $textColor,
            );
        }

        return sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="100%%" height="%d" '
            . 'role="img" aria-label="captcha" style="user-select:none">'
            . '<rect width="%d" height="%d" fill="#003"/>%s%s</svg>',
            $width, $height, $height, $width, $height, $noise, $glyphs,
        );
    }

    /** A fresh random key for a newly installed custom-captcha extension. */
    public static function randomKey(): string
    {
        return Str::random(40);
    }
}
