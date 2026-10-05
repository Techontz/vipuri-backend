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
     * Whether this staff member works across every branch: a super admin, or
     * anyone whose role grants the company-wide permission (e.g. Admin).
     */
    public function isCompanyWide(): bool
    {
        return $this->isSuperAdmin() || $this->hasPermissionTo(Roles::COMPANY_WIDE, Roles::GUARD);
    }

    /**
     * Rank of this staff member's role (roles.level). Staff may only create
     * and manage people of a lower rank.
     */
    public function roleLevel(): int
    {
        if ($this->isSuperAdmin()) {
            return Roles::LEVELS[Roles::SUPER_ADMIN];
        }

        return (int) ($this->roles->max('level') ?? 0);
    }

    /**
     * Whether this staff member may create, edit or deactivate `$other`:
     * always themselves; otherwise someone of a lower rank who sits inside
     * their branch scope. Super admins can only be managed by super admins.
     */
    public function canManageStaff(self $other): bool
    {
        if ($this->id === $other->id || $this->isSuperAdmin()) {
            return true;
        }

        if ($other->isSuperAdmin() || $other->roleLevel() >= $this->roleLevel()) {
            return false;
        }

        return $this->isCompanyWide() || ((int) $other->branch_id === (int) $this->branch_id && $this->branch_id);
    }

    /**
     * Branch ids this staff member may read/write.
     * `null` means "no restriction" (company-wide staff).
     */
    public function scopedBranchIds(): ?array
    {
        if ($this->isCompanyWide()) {
            return null;
        }

        return $this->branch_id ? [$this->branch_id] : [];
    }

    /** Whether this staff member may act on the given branch. */
    public function canAccessBranch(?int $branchId): bool
    {
        if ($this->isCompanyWide()) {
            return true;
        }

        return $branchId !== null && (int) $branchId === (int) $this->branch_id;
    }

    /**
     * Constrain a query that has a `branch_id` column to this staff member's
     * branch. Company-wide staff are left unconstrained.
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
