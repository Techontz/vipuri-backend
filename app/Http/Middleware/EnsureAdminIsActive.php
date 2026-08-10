<?php

namespace App\Http\Middleware;

use App\Constants\Status;
use Closure;
use Illuminate\Http\Request;

/**
 * Blocks deactivated staff. Runs on every admin endpoint so revoking access
 * takes effect immediately, not on next login.
 */
class EnsureAdminIsActive
{
    public function handle(Request $request, Closure $next)
    {
        $admin = $request->user('admin');

        if (! $admin) {
            return responseError('unauthenticated', ['Unauthorized request'], code: 401);
        }

        if ((int) $admin->status !== Status::ENABLE) {
            $admin->currentAccessToken()?->delete();

            return responseError('account_disabled', [
                $admin->ban_reason ?: 'Your staff account has been deactivated.',
            ], code: 403);
        }

        // A non-super-admin must always belong to a branch.
        if (! $admin->isSuperAdmin() && ! $admin->branch_id) {
            return responseError('no_branch', [
                'Your account is not assigned to a branch. Contact a super administrator.',
            ], code: 403);
        }

        return $next($request);
    }
}
