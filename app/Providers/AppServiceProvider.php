<?php

namespace App\Providers;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiting();

        if (config('vipuri.force_ssl')) {
            URL::forceScheme('https');
        }
    }

    private function configureRateLimiting(): void
    {
        // Signed-in callers are limited per account, anonymous ones per IP.
        //
        // Staff get the widest allowance: an admin screen legitimately fires
        // several requests per page load (list + filters + widgets), so a
        // guest-sized budget throttles ordinary work. Anonymous traffic —
        // the traffic worth rate limiting — stays tight.
        RateLimiter::for('api', function (Request $request) {
            if ($adminId = $request->user('admin')?->id) {
                return Limit::perMinute((int) config('vipuri.rate_limit.admin'))->by('admin:' . $adminId);
            }

            if ($userId = $request->user('user')?->id) {
                return Limit::perMinute((int) config('vipuri.rate_limit.user'))->by('user:' . $userId);
            }

            return Limit::perMinute((int) config('vipuri.rate_limit.guest'))->by('ip:' . $request->ip());
        });

        // Gateway webhooks. Generous, because a provider may legitimately retry
        // in bursts, but not unbounded: each call makes us call the provider
        // back, so an open endpoint is an amplifier.
        RateLimiter::for('ipn', fn (Request $request) => Limit::perMinute(
            (int) config('vipuri.rate_limit.ipn')
        )->by('ipn:' . $request->ip()));
    }
}
