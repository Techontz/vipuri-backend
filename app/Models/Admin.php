<?php

namespace App\Models;

use App\Constants\Roles;
use App\Constants\Status;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * VIPURI staff member: Super Admin, Branch Manager or Branch Worker.
 *
 * A null `branch_id` means company-wide scope. Every branch-scoped query in the
 * admin API funnels through {@see Admin::isSuperAdmin()} / {@see Admin::scopedBranchIds()}
 * so branch isolation is enforced in the backend, never in the UI alone.
 */
class Admin extends Authenticatable
{
    use HasApiTokens, HasRoles, Notifiable;

    protected string $guard_name = Roles::GUARD;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => 'boolean',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id')->where('actor_type', 'admin');
    }

    public function scopeActive($query)
    {
        return $query->where('status', Status::ENABLE);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Roles::SUPER_ADMIN);
    }

    public function isBranchManager(): bool
    {
        return $this->hasRole(Roles::BRANCH_MANAGER);
    }

    public function isBranchWorker(): bool
    {
        return $this->hasRole(Roles::BRANCH_WORKER);
    }

    /**
     * Branch ids this staff member may read/write.
     * `null` means "no restriction" (super admin).
     */
    public function scopedBranchIds(): ?array
    {
        if ($this->isSuperAdmin()) {
            return null;
        }

        return $this->branch_id ? [$this->branch_id] : [];
    }

    /** Whether this staff member may act on the given branch. */
    public function canAccessBranch(?int $branchId): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $branchId !== null && (int) $branchId === (int) $this->branch_id;
    }

    /**
     * Constrain a query that has a `branch_id` column to this staff member's
     * branch. Super admins are left unconstrained.
     */
    public function applyBranchScope($query, string $column = 'branch_id')
    {
        $ids = $this->scopedBranchIds();

        if ($ids === null) {
            return $query;
        }

        return $query->whereIn($column, $ids ?: [0]);
    }

    public function getRoleNameAttribute(): ?string
    {
        return $this->getRoleNames()->first();
    }
}
