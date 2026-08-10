<?php

namespace App\Http\Controllers\Api\Admin;

use App\Constants\Roles;
use App\Constants\Status;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Admin;
use App\Models\Branch;
use App\Models\Company;
use App\Services\AuditService;
use App\Services\FileManager;
use App\Traits\ScopesToBranch;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Staff management.
 *
 * A super admin manages everybody. A branch manager may only manage Branch
 * Workers inside their own branch, and can never grant a role above their own
 * or move somebody to a different branch.
 */
class StaffController extends Controller
{
    use ScopesToBranch;

    public function __construct(
        private readonly AuditService $audit,
        private readonly FileManager $files,
    ) {}

    public function index(Request $request)
    {
        $query = Admin::query()
            ->with('branch', 'roles')
            ->when($request->query('search'), fn ($q, $s) => $q->where(function ($inner) use ($s) {
                $inner->where('name', 'like', "%$s%")
                    ->orWhere('email', 'like', "%$s%")
                    ->orWhere('username', 'like', "%$s%");
            }))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->boolean('status')))
            ->when($request->query('role'), fn ($q, $role) => $q->role($role, Roles::GUARD))
            ->when($request->query('branch_id'), fn ($q, $branchId) => $q->where('branch_id', $branchId))
            ->latest('id');

        $this->scopeBranch($query);

        // A manager must not be able to enumerate super admins.
        if (! $this->admin()->isSuperAdmin()) {
            $query->whereDoesntHave('roles', fn ($q) => $q->where('name', Roles::SUPER_ADMIN));
        }

        $staff = $query->paginate(getPaginate(20));

        return responseSuccess('staff', 'Staff fetched', [
            'staff' => AdminResource::collection($staff->items()),
            'pagination' => [
                'current_page' => $staff->currentPage(),
                'last_page' => $staff->lastPage(),
                'total' => $staff->total(),
            ],
            'roles' => $this->assignableRoles(),
        ]);
    }

    public function show(int $id)
    {
        $staff = $this->findManageable($id);

        return responseSuccess('staff_member', 'Staff member fetched', [
            'staff' => new AdminResource($staff->load('branch')),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:admins,email'],
            'username' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:admins,username'],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'role' => ['required', Rule::in($this->assignableRoles())],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(Roles::all())],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $branchId = $this->resolveTargetBranch($data['role'], $data['branch_id'] ?? null);

        $staff = new Admin([
            'company_id' => Company::current()->id,
            'branch_id' => $branchId,
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'],
            'dial_code' => $data['dial_code'] ?? '+255',
            'mobile' => $data['mobile'] ?? null,
            'password' => $data['password'],
            'status' => Status::ENABLE,
        ]);

        if ($request->hasFile('image')) {
            $staff->image = $this->files->uploadImage($request->file('image'), 'adminProfile');
        }

        $staff->save();
        $staff->syncRoles([$data['role']]);

        if ($this->admin()->isSuperAdmin() && ! empty($data['permissions'])) {
            $staff->syncPermissions($data['permissions']);
        }

        $this->audit->log(
            'staff.created',
            $staff,
            newValues: ['name' => $staff->name, 'role' => $data['role'], 'branch_id' => $branchId],
            description: "Staff {$staff->name} created as {$data['role']}",
            branchId: $branchId,
        );

        return responseSuccess('staff_created', 'Staff member created', [
            'staff' => new AdminResource($staff->load('branch')),
        ]);
    }

    public function update(Request $request, int $id)
    {
        $staff = $this->findManageable($id);
        $before = $staff->getAttributes();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:191', 'unique:admins,email,' . $staff->id],
            'username' => ['required', 'string', 'min:3', 'max:80', 'regex:/^[A-Za-z0-9._-]+$/', 'unique:admins,username,' . $staff->id],
            'dial_code' => ['nullable', 'string', 'max:10'],
            'mobile' => ['nullable', 'string', 'max:40'],
            'password' => ['nullable', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'role' => ['nullable', Rule::in($this->assignableRoles())],
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(Roles::all())],
            'image' => ['nullable', 'image', 'max:5120'],
        ]);

        $staff->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'username' => $data['username'],
            'dial_code' => $data['dial_code'] ?? $staff->dial_code,
            'mobile' => $data['mobile'] ?? null,
        ]);

        if (! empty($data['password'])) {
            $staff->password = $data['password'];
            // Force a re-login everywhere when an administrator resets a password.
            $staff->tokens()->delete();
        }

        if ($request->hasFile('image')) {
            $staff->image = $this->files->uploadImage($request->file('image'), 'adminProfile', $staff->image);
        }

        // Only a super admin may move somebody between branches or change role.
        if ($this->admin()->isSuperAdmin()) {
            if (! empty($data['role'])) {
                $staff->syncRoles([$data['role']]);
            }

            if (array_key_exists('branch_id', $data)) {
                $role = $data['role'] ?? $staff->getRoleNames()->first();
                $staff->branch_id = $this->resolveTargetBranch($role, $data['branch_id']);
            }

            if (array_key_exists('permissions', $data)) {
                $staff->syncPermissions($data['permissions'] ?? []);
            }
        }

        $staff->save();

        $this->audit->logUpdate('staff.updated', $staff, $before, "Staff {$staff->name} updated");

        return responseSuccess('staff_updated', 'Staff member updated', [
            'staff' => new AdminResource($staff->fresh('branch')),
        ]);
    }

    public function changeStatus(Request $request, int $id)
    {
        $staff = $this->findManageable($id);

        if ($staff->id === $this->admin()->id) {
            return responseError('self_action', ['You cannot change your own account status']);
        }

        $data = $request->validate(['ban_reason' => ['nullable', 'string', 'max:255']]);

        $staff->status = $staff->status ? Status::DISABLE : Status::ENABLE;
        $staff->ban_reason = $staff->status ? null : ($data['ban_reason'] ?? null);
        $staff->save();

        if (! $staff->status) {
            // Revoking access must take effect immediately.
            $staff->tokens()->delete();
        }

        $this->audit->log(
            'staff.status_changed',
            $staff,
            newValues: ['status' => $staff->status],
            description: "Staff {$staff->name} " . ($staff->status ? 'activated' : 'deactivated'),
            branchId: $staff->branch_id,
        );

        return responseSuccess('staff_status_changed', 'Staff status updated', [
            'staff' => new AdminResource($staff->fresh('branch')),
        ]);
    }

    /** Roles + the full permission catalogue for the assignment screen. */
    public function rolesAndPermissions()
    {
        return responseSuccess('roles_permissions', 'Roles and permissions fetched', [
            'roles' => Role::where('guard_name', Roles::GUARD)->get()->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $role->permissions->pluck('name')->values(),
                'staff_count' => Admin::role($role->name, Roles::GUARD)->count(),
            ])->values(),
            'permission_groups' => collect(Roles::GROUPS)->map(fn ($items, $group) => [
                'group' => $group,
                'permissions' => collect($items)->map(fn ($name) => [
                    'name' => $name,
                    'label' => keyToTitle(str_replace('.', ' ', $name)),
                ])->values(),
            ])->values(),
            'assignable_roles' => $this->assignableRoles(),
        ]);
    }

    /** Rewrite the permission set attached to a role. Super admin only. */
    public function updateRolePermissions(Request $request, int $roleId)
    {
        if (! $this->admin()->isSuperAdmin()) {
            abort(403, 'Only a super administrator can edit roles');
        }

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Roles::all())],
        ]);

        $role = Role::where('guard_name', Roles::GUARD)->findOrFail($roleId);

        if ($role->name === Roles::SUPER_ADMIN) {
            return responseError('protected_role', ['The Super Admin role always holds every permission']);
        }

        $permissions = Permission::where('guard_name', Roles::GUARD)
            ->whereIn('name', $data['permissions'])
            ->get();

        $role->syncPermissions($permissions);

        $this->audit->log(
            'role.permissions_updated',
            $role,
            newValues: ['permissions' => $data['permissions']],
            description: "Permissions updated for role {$role->name}",
        );

        return responseSuccess('role_updated', 'Role permissions updated');
    }

    /* ------------------------------------------------------------------ *
     | Internals
     * ------------------------------------------------------------------ */

    /** Roles the caller is allowed to hand out. */
    private function assignableRoles(): array
    {
        return $this->admin()->isSuperAdmin()
            ? Roles::ALL
            : [Roles::BRANCH_WORKER];
    }

    /**
     * Load a staff member the caller is allowed to manage, or 403.
     * This is the single choke point that stops cross-branch tampering.
     */
    private function findManageable(int $id): Admin
    {
        $staff = Admin::with('roles')->findOrFail($id);
        $caller = $this->admin();

        if ($caller->isSuperAdmin()) {
            return $staff;
        }

        if ((int) $staff->branch_id !== (int) $caller->branch_id) {
            abort(403, 'This staff member belongs to another branch');
        }

        // A manager may only touch workers, never other managers or admins.
        if (! $staff->hasRole(Roles::BRANCH_WORKER) && $staff->id !== $caller->id) {
            abort(403, 'You can only manage branch workers');
        }

        return $staff;
    }

    /** Super Admins are company-wide; every other role needs a branch. */
    private function resolveTargetBranch(?string $role, ?int $requested): ?int
    {
        if ($role === Roles::SUPER_ADMIN) {
            return null;
        }

        if (! $this->admin()->isSuperAdmin()) {
            return $this->admin()->branch_id;
        }

        if (! $requested) {
            abort(422, 'A branch is required for this role');
        }

        Branch::findOrFail($requested);

        return $requested;
    }
}
