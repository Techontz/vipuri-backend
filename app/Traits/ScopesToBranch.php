<?php

namespace App\Traits;

use App\Models\Admin;
use Illuminate\Database\Eloquent\Builder;

/**
 * Branch isolation helpers for admin controllers.
 *
 * Every admin endpoint that reads or writes branch-owned data must run its
 * query through {@see scopeBranch()} or validate with {@see authorizeBranch()}.
 * Nothing relies on the UI hiding a control.
 */
trait ScopesToBranch
{
    protected function admin(): Admin
    {
        return auth('admin')->user();
    }

    /** Restrict a query with a branch_id column to the caller's branch. */
    protected function scopeBranch(Builder $query, string $column = 'branch_id'): Builder
    {
        return $this->admin()->applyBranchScope($query, $column);
    }

    /**
     * Abort unless the caller may act on this branch.
     * Super admins pass for every branch, including null.
     */
    protected function authorizeBranch(?int $branchId): void
    {
        if (! $this->admin()->canAccessBranch($branchId)) {
            abort(403, 'You do not have access to this branch');
        }
    }

    /**
     * The branch a write should be attributed to: the caller's own branch, or
     * the requested one when the caller is a super admin.
     */
    protected function resolveBranchId(?int $requested = null): ?int
    {
        $admin = $this->admin();

        if (! $admin->isSuperAdmin()) {
            return $admin->branch_id;
        }

        return $requested;
    }

    /** Branch ids visible to the caller, or null for "all". */
    protected function visibleBranchIds(): ?array
    {
        return $this->admin()->scopedBranchIds();
    }
}
