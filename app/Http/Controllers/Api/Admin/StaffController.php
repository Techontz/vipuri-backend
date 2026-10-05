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
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Staff and role management.
 *
 * Every role has a rank (roles.level). Staff can only create and manage
 * people whose role ranks below their own, inside their own branch unless
 * their role is company-wide — so an HR officer can hire sales assistants in
 * their branch, a manager can hire HR and sales staff, an admin can staff any
 * branch, and only a super admin can create another super admin.
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

        // Only super admins can see super admins.
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
            'role_options' => $this->roleOptions(),
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
            // Keeping the current role is always allowed; changing it needs
            // a role the caller may hand out.
            'role' => ['nullable', Rule::in([...$this->assignableRoles(), $staff->getRoleNames()->first()])],
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

        $caller = $this->admin();
        $isSelf = $staff->id === $caller->id;

        // Nobody changes their own role or branch; otherwise the role must be
        // one the caller may hand out (validated above).
        if (! empty($data['role']) && $data['role'] !== $staff->getRoleNames()->first()) {
            if ($isSelf) {
                $this->refuse('You cannot change your own role', 'self_role');
            }

            $staff->syncRoles([$data['role']]);
        }

        // Moving someone between branches is for company-wide staff only;
        // branch staff keep everyone in their own branch.
        if (! $isSelf && $caller->isCompanyWide() && array_key_exists('branch_id', $data)) {
            $role = $data['role'] ?? $staff->getRoleNames()->first();
            $staff->branch_id = $this->resolveTargetBranch($role, $data['branch_id']);
        }

        if ($caller->isSuperAdmin() && array_key_exists('permissions', $data)) {
            $staff->syncPermissions($data['permissions'] ?? []);
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

    /** Roles + the full permission catalogue for the roles screen. */
    public function rolesAndPermissions()
    {
        $caller = $this->admin();

        return responseSuccess('roles_permissions', 'Roles and permissions fetched', [
            'roles' => $this->roleQuery()->get()->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'description' => $role->description,
                'level' => (int) $role->level,
                'company_wide' => $this->roleIsCompanyWide($role),
                'is_builtin' => in_array($role->name, Roles::ALL, true),
                'can_edit' => $this->canEditRole($role),
                'permissions' => $role->permissions->pluck('name')->values(),
                'staff_count' => Admin::role($role->name, Roles::GUARD)->count(),
            ])->values(),
            'permission_groups' => collect(Roles::GROUPS)->map(fn ($items, $group) => [
                'group' => $group,
                'permissions' => collect($items)->map(fn ($name) => [
                    'name' => $name,
                    'label' => self::permissionLabel($name),
                ])->values(),
            ])->values(),
            'assignable_roles' => $this->assignableRoles(),
            'role_options' => $this->roleOptions(),
            'my_level' => $caller->roleLevel(),
            'grantable_permissions' => $this->grantablePermissions(),
        ]);
    }

    /** Create a custom role, e.g. "Storekeeper" or "Cashier". */
    public function storeRole(Request $request)
    {
        $data = $this->validateRole($request);

        $role = Role::create(['name' => $data['name'], 'guard_name' => Roles::GUARD]);
        $role->forceFill(['level' => $data['level'], 'description' => $data['description'] ?? null])->save();
        $role->syncPermissions($data['permissions']);

        $this->audit->log(
            'role.created',
            $role,
            newValues: ['level' => $data['level'], 'permissions' => $data['permissions']],
            description: "Role {$role->name} created",
        );

        return responseSuccess('role_created', 'Role created', ['role' => ['id' => $role->id, 'name' => $role->name]]);
    }

    /** Rename, re-rank or change the permissions of a role. */
    public function updateRole(Request $request, int $roleId)
    {
        $role = $this->findEditableRole($roleId);
        $data = $this->validateRole($request, $role);

        // Built-in role names are referenced by the system; they keep them.
        if (! in_array($role->name, Roles::ALL, true)) {
            $role->name = $data['name'];
        }

        $role->forceFill(['level' => $data['level'], 'description' => $data['description'] ?? null])->save();
        $role->syncPermissions($data['permissions']);

        $this->audit->log(
            'role.updated',
            $role,
            newValues: ['level' => $data['level'], 'permissions' => $data['permissions']],
            description: "Role {$role->name} updated",
        );

        return responseSuccess('role_updated', 'Role updated');
    }

    /** Older clients: change only the permission set of a role. */
    public function updateRolePermissions(Request $request, int $roleId)
    {
        $role = $this->findEditableRole($roleId);

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($this->grantablePermissions())],
        ]);

        $role->syncPermissions($data['permissions']);

        $this->audit->log(
            'role.permissions_updated',
            $role,
            newValues: ['permissions' => $data['permissions']],
            description: "Permissions updated for role {$role->name}",
        );

        return responseSuccess('role_updated', 'Role permissions updated');
    }

    /** Delete a custom role that nobody holds any more. */
    public function destroyRole(int $roleId)
    {
        $role = $this->findEditableRole($roleId);

        if (in_array($role->name, Roles::ALL, true)) {
            return responseError('protected_role', ['Built-in roles cannot be deleted; change their permissions instead']);
        }

        $holders = Admin::role($role->name, Roles::GUARD)->count();

        if ($holders > 0) {
            return responseError('role_in_use', ["{$holders} staff member(s) still have this role. Give them another role first."]);
        }

        $this->audit->log('role.deleted', $role, description: "Role {$role->name} deleted");
        $role->delete();

        return responseSuccess('role_deleted', 'Role deleted');
    }

    /* ------------------------------------------------------------------ *
     | Internals
     * ------------------------------------------------------------------ */

    /** Names of the roles the caller is allowed to hand out. */
    private function assignableRoles(): array
    {
        return $this->assignableRoleModels()->pluck('name')->values()->all();
    }

    /** Assignable roles with what the staff form needs to explain them. */
    private function roleOptions(): array
    {
        return $this->assignableRoleModels()->map(fn (Role $role) => [
            'name' => $role->name,
            'description' => $role->description,
            'level' => (int) $role->level,
            'company_wide' => $this->roleIsCompanyWide($role),
        ])->values()->all();
    }

    /**
     * Roles ranked below the caller's own (a super admin may hand out any
     * role). Company-wide roles are only for company-wide callers.
     */
    private function assignableRoleModels()
    {
        $caller = $this->admin();

        return $this->roleQuery()->get()->filter(function (Role $role) use ($caller) {
            if ($caller->isSuperAdmin()) {
                return true;
            }

            if ((int) $role->level >= $caller->roleLevel() || $role->name === Roles::SUPER_ADMIN) {
                return false;
            }

            return $caller->isCompanyWide() || ! $this->roleIsCompanyWide($role);
        })->values();
    }

    private function roleQuery()
    {
        return Role::where('guard_name', Roles::GUARD)->with('permissions')->orderByDesc('level')->orderBy('name');
    }

    private function roleIsCompanyWide(Role $role): bool
    {
        return $role->name === Roles::SUPER_ADMIN
            || $role->permissions->contains('name', Roles::COMPANY_WIDE);
    }

    /** Permissions the caller may put into a role: only ones they hold. */
    private function grantablePermissions(): array
    {
        $caller = $this->admin();

        if ($caller->isSuperAdmin()) {
            return Roles::all();
        }

        return array_values(array_intersect(Roles::all(), $caller->getAllPermissions()->pluck('name')->all()));
    }

    private function canEditRole(Role $role): bool
    {
        $caller = $this->admin();

        if ($role->name === Roles::SUPER_ADMIN || ! $caller->can('role.manage')) {
            return false;
        }

        if ($caller->isSuperAdmin()) {
            return true;
        }

        // Below the caller, and nothing in it the caller doesn't hold.
        return (int) $role->level < $caller->roleLevel()
            && $role->permissions->pluck('name')->diff($this->grantablePermissions())->isEmpty();
    }

    private function findEditableRole(int $roleId): Role
    {
        $role = Role::where('guard_name', Roles::GUARD)->with('permissions')->findOrFail($roleId);

        if ($role->name === Roles::SUPER_ADMIN) {
            $this->refuse('The Super Admin role always holds every permission', 'protected_role', 422);
        }

        if (! $this->canEditRole($role)) {
            $this->refuse('You can only edit roles ranked below your own');
        }

        return $role;
    }

    private function validateRole(Request $request, ?Role $role = null): array
    {
        $caller = $this->admin();
        $maxLevel = $caller->isSuperAdmin() ? Roles::LEVELS[Roles::SUPER_ADMIN] - 1 : $caller->roleLevel() - 1;

        return $request->validate([
            'name' => [
                $role ? 'sometimes' : 'required', 'string', 'min:2', 'max:60',
                Rule::unique(config('permission.table_names.roles', 'roles'), 'name')
                    ->where('guard_name', Roles::GUARD)->ignore($role?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'level' => ['required', 'integer', 'min:1', 'max:' . max(1, $maxLevel)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in($this->grantablePermissions())],
        ], [
            'level.max' => 'A role must rank below your own.',
            'permissions.*.in' => 'You can only give a role permissions you hold yourself.',
        ]) + ['name' => $role?->name];
    }

    /**
     * Stop with a message the admin panel can show as-is.
     *
     * `abort(403, '…')` is rendered by the global API handler as "Something
     * went wrong" whenever APP_DEBUG is off, so in production staff never saw
     * why they were refused. An exception that renders itself keeps the real
     * reason in the standard envelope.
     */
    private function refuse(string $message, string $remark = 'forbidden', int $status = 403): never
    {
        throw new class($message, $remark, $status) extends \RuntimeException
        {
            public function __construct(string $message, private readonly string $remark, private readonly int $status)
            {
                parent::__construct($message);
            }

            /** An expected refusal, not an error worth logging. */
            public function report(): bool
            {
                return true;
            }

            public function render()
            {
                return responseError($this->remark, [$this->getMessage()], [], $this->status);
            }
        };
    }

    public static function permissionLabel(string $name): string
    {
        return [
            'branch.all' => 'Work across all branches',
            'role.manage' => 'Create and edit roles',
            'pos.sell' => 'Sell at the counter',
            'pos.discount' => 'Change prices at the counter',
            'inventory.receive' => 'Receive new stock',
        ][$name] ?? keyToTitle(str_replace('.', ' ', $name));
    }

    /**
     * Load a staff member the caller is allowed to manage, or 403.
     * This is the single choke point that stops cross-branch tampering.
     */
    private function findManageable(int $id): Admin
    {
        $staff = Admin::with('roles')->findOrFail($id);

        if (! $this->admin()->canManageStaff($staff)) {
            $this->refuse('You can only manage staff ranked below you in your own branch');
        }

        return $staff;
    }

    /**
     * Company-wide roles may have no branch; everyone else needs one. Branch
     * staff can only place people in their own branch.
     */
    private function resolveTargetBranch(?string $role, ?int $requested): ?int
    {
        $roleModel = $role ? Role::where('guard_name', Roles::GUARD)->where('name', $role)->with('permissions')->first() : null;

        if ($roleModel && $this->roleIsCompanyWide($roleModel)) {
            return $requested ? Branch::findOrFail($requested)->id : null;
        }

        if (! $this->admin()->isCompanyWide()) {
            return $this->admin()->branch_id;
        }

        if (! $requested) {
            throw ValidationException::withMessages(['branch_id' => 'Choose a branch: this role works in one branch.']);
        }

        return Branch::findOrFail($requested)->id;
    }
}
