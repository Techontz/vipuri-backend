<?php

namespace App\Http\Middleware;

use App\Support\CmsContent;
use Closure;
use Illuminate\Http\Request;

/**
 * Storefront maintenance mode. Staff endpoints are never blocked so the shop
 * can be brought back online from the admin panel.
 */
class EnsureNotInMaintenance
{
    public function handle(Request $request, Closure $next)
    {
        if (gs('maintenance_mode')) {
            return response()->json([
                'remark' => 'maintenance_mode',
                'status' => 'error',
                'message' => ['error' => ['The store is currently under maintenance']],
                'data' => [
                    // Resolved through CmsContent so the image arrives as an
                    // absolute URL the storefront can render directly.
                    'maintenance' => CmsContent::data('maintenance'),
                ],
            ], 503);
        }

        return $next($request);
    }
}
