<?php

use App\Constants\Roles;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds `product.delete` and grants it to the super admin.
 *
 * A migration rather than a re-run of RolePermissionSeeder, because the seeder
 * re-syncs every role and would undo permissions changed in the staff screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'product.delete', 'guard_name' => Roles::GUARD]);

        Role::where('name', Roles::SUPER_ADMIN)->where('guard_name', Roles::GUARD)->first()?->givePermissionTo($permission);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'product.delete')->where('guard_name', Roles::GUARD)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
