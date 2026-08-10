<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Admin;
use App\Models\UserLogin;
use App\Services\AuditService;
use App\Services\Captcha;
use App\Services\FileManager;
use App\Support\UserAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/**
 * Staff authentication. Separate guard, separate tokens, separate throttle.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly FileManager $files,
        private readonly Captcha $captcha,
    ) {}

    public function login(Request $request)
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string'],
        ]);

        if ($failure = $this->captcha->verify($request)) {
            return responseError('captcha_failed', [$failure]);
        }

        $throttleKey = 'admin-login:' . Str::lower($data['username']) . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return responseError('too_many_attempts', [
                'Too many attempts. Try again in ' . RateLimiter::availableIn($throttleKey) . ' seconds.',
            ], code: 429);
        }

        $field = filter_var($data['username'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $admin = Admin::with('branch')->where($field, $data['username'])->first();

        if (! $admin || ! Hash::check($data['password'], $admin->password)) {
            RateLimiter::hit($throttleKey, 300);

            return responseError('invalid_credentials', ['Invalid login credentials'], code: 401);
        }

        if ((int) $admin->status !== Status::ENABLE) {
            return responseError('account_disabled', [
                $admin->ban_reason ?: 'Your staff account has been deactivated',
            ], code: 403);
        }

        RateLimiter::clear($throttleKey);

        $admin->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        UserLogin::create([
            'user_id' => $admin->id,
            'guard' => 'admin',
            'user_ip' => $request->ip(),
            'browser' => UserAgent::browser((string) $request->userAgent()),
            'os' => UserAgent::os((string) $request->userAgent()),
        ]);

        $this->audit->log('staff.login', $admin, description: "{$admin->name} signed in", branchId: $admin->branch_id);

        // Sanctum abilities mirror the staff member's permissions so a stolen
        // token can never exceed what the account itself may do.
        $abilities = $admin->getAllPermissions()->pluck('name')->all() ?: ['*'];

        return responseSuccess('logged_in', 'Login successful', [
            'admin' => new AdminResource($admin),
            'token' => $admin->createToken('admin-panel', $abilities)->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $admin = $request->user('admin');
        $admin?->currentAccessToken()?->delete();

        if ($admin) {
            $this->audit->log('staff.logout', $admin, description: "{$admin->name} signed out", branchId: $admin->branch_id);
        }

        return responseSuccess('logged_out', 'You have been logged out');
    }

    public function me(Request $request)
    {
        return responseSuccess('profile', 'Profile fetched', [
            'admin' => new AdminResource($request->user('admin')->load('branch')),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $admin = $request->user('admin');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:admins,email,' . $admin->id],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        if ($request->hasFile('image')) {
            $data['image'] = $this->files->uploadImage($request->file('image'), 'adminProfile', $admin->image);
        }

        $admin->fill($data)->save();

        return responseSuccess('profile_updated', 'Profile updated', [
            'admin' => new AdminResource($admin->fresh('branch')),
        ]);
    }

    public function changePassword(Request $request)
    {
        $admin = $request->user('admin');

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        if (! Hash::check($data['current_password'], $admin->password)) {
            return responseError('wrong_password', ['Your current password is incorrect']);
        }

        $admin->password = $data['password'];
        $admin->save();

        $currentId = $admin->currentAccessToken()?->id;
        $admin->tokens()->when($currentId, fn ($q) => $q->where('id', '!=', $currentId))->delete();

        $this->audit->log('staff.password_changed', $admin, description: "{$admin->name} changed their password", branchId: $admin->branch_id);

        return responseSuccess('password_changed', 'Your password has been changed');
    }

    public function forgotPassword(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email']]);

        if ($failure = $this->captcha->verify($request)) {
            return responseError('captcha_failed', [$failure]);
        }

        $admin = Admin::where('email', $data['email'])->first();

        if ($admin) {
            $code = random_int(100000, 999999);

            DB::table('admin_password_resets')->where('email', $admin->email)->delete();
            DB::table('admin_password_resets')->insert([
                'email' => $admin->email,
                'token' => (string) $code,
                'status' => 1,
                'created_at' => now(),
            ]);

            try {
                Mail::html(
                    "<p>Hello {$admin->name},</p><p>Your VIPURI staff password reset code is <strong>{$code}</strong>. It expires in one hour.</p>",
                    fn ($m) => $m->to($admin->email)->subject('VIPURI staff password reset'),
                );
            } catch (\Throwable) {
                // Delivery failures must not reveal whether the account exists.
            }
        }

        return responseSuccess('reset_code_sent', 'If that e-mail belongs to a staff account, a reset code has been sent');
    }

    public function verifyResetCode(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'max:10'],
        ]);

        $row = DB::table('admin_password_resets')
            ->where('email', $data['email'])
            ->where('token', $data['code'])
            ->where('status', 1)
            ->first();

        if (! $row || now()->diffInMinutes($row->created_at) > 60) {
            return responseError('invalid_code', ['The reset code is invalid or has expired']);
        }

        $token = Str::random(60);

        DB::table('admin_password_resets')
            ->where('email', $data['email'])
            ->update(['token' => $token, 'status' => 2]);

        return responseSuccess('code_verified', 'Code verified', ['token' => $token]);
    }

    public function resetPassword(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ]);

        $row = DB::table('admin_password_resets')
            ->where('email', $data['email'])
            ->where('token', $data['token'])
            ->where('status', 2)
            ->first();

        if (! $row) {
            return responseError('invalid_token', ['This reset link is no longer valid']);
        }

        $admin = Admin::where('email', $data['email'])->firstOrFail();
        $admin->password = $data['password'];
        $admin->save();
        $admin->tokens()->delete();

        DB::table('admin_password_resets')->where('email', $data['email'])->delete();

        $this->audit->log('staff.password_reset', $admin, description: "Password reset for {$admin->name}", branchId: $admin->branch_id);

        return responseSuccess('password_reset', 'Password reset. Please sign in.');
    }


}
