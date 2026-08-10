<?php

namespace Database\Seeders;

use App\Constants\Roles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        App::make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Roles::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => Roles::GUARD]);
        }

        $superAdmin = Role::firstOrCreate(['name' => Roles::SUPER_ADMIN, 'guard_name' => Roles::GUARD]);
        $manager = Role::firstOrCreate(['name' => Roles::BRANCH_MANAGER, 'guard_name' => Roles::GUARD]);
        $worker = Role::firstOrCreate(['name' => Roles::BRANCH_WORKER, 'guard_name' => Roles::GUARD]);

        // The super admin holds every permission, always.
        $superAdmin->syncPermissions(Permission::where('guard_name', Roles::GUARD)->get());

        $manager->syncPermissions(
            Permission::where('guard_name', Roles::GUARD)
                ->whereIn('name', Roles::BRANCH_MANAGER_PERMISSIONS)
                ->get()
        );

        $worker->syncPermissions(
            Permission::where('guard_name', Roles::GUARD)
                ->whereIn('name', Roles::BRANCH_WORKER_PERMISSIONS)
                ->get()
        );

        App::make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
