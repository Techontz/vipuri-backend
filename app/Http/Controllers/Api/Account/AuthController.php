<?php

namespace App\Http\Controllers\Api\Account;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\UserLogin;
use App\Services\AuditService;
use App\Services\CartIdentity;
use App\Services\Captcha;
use App\Services\CartService;
use App\Services\NotificationService;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Customer authentication: registration, login, verification, password reset.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly CartService $cart,
        private readonly CartIdentity $identity,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly Captcha $captcha,
    ) {}

    public function register(Request $request)
    {
        if (! gs('registration')) {
            return responseError('registration_disabled', ['Registration is currently closed']);
        }

        if ($failure = $this->captcha->verify($request)) {
            return responseError('captcha_failed', [$failure]);
        }

        $data = $request->validate([
            'firstname' => ['required', 'string', 'max:80'],
            'lastname' => ['required', 'string', 'max:80'],
            'username' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'confirmed', $this->passwordRule()],
            'agree' => ['nullable', 'accepted'],
        ]);

        $user = User::create([
            'firstname' => $data['firstname'],
            'lastname' => $data['lastname'],
            'username' => $data['username'],
            'email' => $data['email'],
            'dial_code' => $data['dial_code'] ?? '+255',
            'mobile' => $data['mobile'] ?? null,
            'password' => $data['password'],
            'country_name' => 'Tanzania',
            'country_code' => 'TZ',
            'status' => Status::USER_ACTIVE,
            'ev' => gs('ev') ? Status::UNVERIFIED : Status::VERIFIED,
            'sv' => gs('sv') ? Status::UNVERIFIED : Status::VERIFIED,
            'profile_complete' => Status::YES,
        ]);

        if (gs('ev') || gs('sv')) {
            $this->issueVerificationCode($user);
        }

        $this->cart->mergeGuestData($user->id, $request->header(CartIdentity::HEADER));
        $this->recordLogin($user, $request);
        $this->audit->log('customer.registered', $user, description: "Customer {$user->username} registered");

        return responseSuccess('registered', 'Welcome to VIPURI', [
            'user' => new UserResource($user),
            'token' => $user->createToken('storefront')->plainTextToken,
        ]);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
        ]);

        if ($failure = $this->captcha->verify($request)) {
            return responseError('captcha_failed', [$failure]);
        }

        $throttleKey = 'login:' . Str::lower($data['username']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return responseError('too_many_attempts', [
                'Too many login attempts. Try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.',
            ], code: 429);
        }

        $field = filter_var($data['username'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::where($field, $data['username'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            return responseError('invalid_credentials', ['Invalid login credentials'], code: 401);
        }

        if ((int) $user->status !== Status::USER_ACTIVE) {
            return responseError('account_banned', [
                $user->ban_reason ?: 'Your account has been suspended',
            ], code: 403);
        }

        RateLimiter::clear($throttleKey);

        $this->cart->mergeGuestData($user->id, $request->header(CartIdentity::HEADER));
        $this->recordLogin($user, $request);

        return responseSuccess('logged_in', 'Login successful', [
            'user' => new UserResource($user),
            'token' => $user->createToken('storefront')->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user('user')?->currentAccessToken()?->delete();

        return responseSuccess('logged_out', 'You have been logged out');
    }

    public function me(Request $request)
    {
        return responseSuccess('profile', 'Profile fetched', [
            'user' => new UserResource($request->user('user')),
        ]);
    }

    /** Availability check used by the registration form. */
    public function checkAvailability(Request $request)
    {
        $data = $request->validate([
            'field' => ['required', Rule::in(['username', 'email', 'mobile'])],
            'value' => ['required', 'string', 'max:191'],
        ]);

        return responseSuccess('availability', 'Availability checked', [
            'available' => ! User::where($data['field'], $data['value'])->exists(),
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Verification
     * ------------------------------------------------------------------ */

    public function sendVerificationCode(Request $request)
    {
        $user = $request->user('user');

        if ($user->ev && $user->sv) {
            return responseSuccess('already_verified', 'Your account is already verified');
        }

        $this->issueVerificationCode($user);

        return responseSuccess('code_sent', 'A verification code has been sent');
    }

    public function verify(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:10'],
            'type' => ['required', Rule::in(['email', 'mobile'])],
        ]);

        $user = $request->user('user');

        if (! $user->ver_code || ! hash_equals((string) $user->ver_code, (string) $data['code'])) {
            return responseError('invalid_code', ['The verification code is incorrect']);
        }

        if ($user->ver_code_send_at && $user->ver_code_send_at->addMinutes(15)->isPast()) {
            return responseError('expired_code', ['The verification code has expired']);
        }

        $user->{$data['type'] === 'email' ? 'ev' : 'sv'} = Status::VERIFIED;
        $user->ver_code = null;
        $user->ver_code_send_at = null;
        $user->save();

        return responseSuccess('verified', 'Verification successful', [
            'user' => new UserResource($user),
        ]);
    }

    /* ------------------------------------------------------------------ *
     | Password reset (code based, as in the source system)
     * ------------------------------------------------------------------ */

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        if ($failure = $this->captcha->verify($request)) {
            return responseError('captcha_failed', [$failure]);
        }

        $user = User::where('email', $data['email'])->first();

        // Always answer the same way so the endpoint cannot enumerate accounts.
        if ($user) {
            $code = random_int(100000, 999999);

            DB::table('password_resets')->where('email', $user->email)->delete();
            DB::table('password_resets')->insert([
                'email' => $user->email,
                'token' => (string) $code,
                'status' => 1,
                'created_at' => now(),
            ]);

            $this->notifications->toUser($user, 'PASS_RESET_CODE', ['code' => $code]);
        }

        return responseSuccess('reset_code_sent', 'If that e-mail is registered, a reset code has been sent');
    }

    public function verifyResetCode(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $row = DB::table('password_resets')
            ->where('email', $data['email'])
            ->where('token', $data['code'])
            ->where('status', 1)
            ->first();

        if (! $row) {
            return responseError('invalid_code', ['The reset code is incorrect']);
        }

        if (now()->diffInMinutes($row->created_at) > 60) {
            return responseError('expired_code', ['The reset code has expired']);
        }

        // Exchange the short code for a single-use token.
        $token = Str::random(60);

        DB::table('password_resets')
            ->where('email', $data['email'])
            ->update(['token' => $token, 'status' => 2]);

        return responseSuccess('code_verified', 'Code verified', ['token' => $token]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', $this->passwordRule()],
        ]);

        $row = DB::table('password_resets')
            ->where('email', $data['email'])
            ->where('token', $data['token'])
            ->where('status', 2)
            ->first();

        if (! $row) {
            return responseError('invalid_token', ['This reset link is no longer valid']);
        }

        $user = User::where('email', $data['email'])->firstOrFail();
        $user->password = $data['password'];
        $user->save();

        // Invalidate every existing session and the reset token.
        $user->tokens()->delete();
        DB::table('password_resets')->where('email', $data['email'])->delete();

        $this->notifications->toUser($user, 'PASS_RESET_DONE');
        $this->audit->log('customer.password_reset', $user, description: "Password reset for {$user->username}");

        return responseSuccess('password_reset', 'Your password has been reset. Please sign in.');
    }

    /* ------------------------------------------------------------------ *
     | Internals
     * ------------------------------------------------------------------ */

    private function passwordRule(): Password
    {
        $rule = Password::min(6);

        if (gs('secure_password')) {
            $rule = Password::min(8)->mixedCase()->numbers()->symbols();
        }

        return $rule;
    }

    private function issueVerificationCode(User $user): void
    {
        $user->ver_code = (string) random_int(100000, 999999);
        $user->ver_code_send_at = now();
        $user->save();

        $this->notifications->toUser($user, 'EVER_CODE', ['code' => $user->ver_code]);
    }

    private function recordLogin(User $user, Request $request): void
    {
        $agent = (string) $request->userAgent();

        UserLogin::create([
            'user_id' => $user->id,
            'guard' => 'user',
            'user_ip' => $request->ip(),
            'browser' => UserAgent::browser($agent),
            'os' => UserAgent::os($agent),
            'country_name' => $user->country_name,
            'country_code' => $user->country_code,
        ]);
    }


}
