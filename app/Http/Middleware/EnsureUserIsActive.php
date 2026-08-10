<?php

namespace App\Http\Middleware;

use App\Constants\Status;
use Closure;
use Illuminate\Http\Request;

/**
 * Blocks banned customers from every authenticated storefront endpoint.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('user');

        if ($user && (int) $user->status !== Status::USER_ACTIVE) {
            $user->currentAccessToken()?->delete();

            return responseError('account_banned', [
                $user->ban_reason ?: 'Your account has been suspended. Please contact support.',
            ], code: 403);
        }

        return $next($request);
    }
}
