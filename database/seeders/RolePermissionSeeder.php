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

        // Every built-in role with its rank, description and default
        // permissions. The super admin always holds every permission.
        foreach (Roles::defaults() as $name => $permissions) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => Roles::GUARD]);
            $role->forceFill([
                'level' => Roles::LEVELS[$name],
                'description' => Roles::DESCRIPTIONS[$name],
            ])->save();

            $role->syncPermissions(
                Permission::where('guard_name', Roles::GUARD)->whereIn('name', $permissions)->get()
            );
        }

        App::make(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
