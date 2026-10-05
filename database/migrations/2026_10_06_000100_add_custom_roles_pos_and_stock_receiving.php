<?php

use App\Constants\Roles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Custom roles, company-wide admins, counter sales and stock receiving.
 *
 * Additive only, so it is safe on a live database: it adds two columns to
 * roles, creates the new permissions, creates the Admin / HR Officer /
 * Sales Assistant roles if they are missing, and grants the new permissions
 * to existing roles without removing anything an administrator configured.
 */
return new class extends Migration
{
    private const NEW_PERMISSIONS = [
        'branch.all', 'role.manage', 'pos.sell', 'pos.discount', 'inventory.receive',
    ];

    public function up(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $t) use ($table) {
            if (! Schema::hasColumn($table, 'level')) {
                $t->unsignedTinyInteger('level')->default(Roles::DEFAULT_LEVEL)->after('guard_name');
            }
            if (! Schema::hasColumn($table, 'description')) {
                $t->string('description', 255)->nullable()->after('level');
            }
        });

        App::make(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Roles::all() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => Roles::GUARD]);
        }

        foreach (Roles::ALL as $name) {
            $role = Role::where('name', $name)->where('guard_name', Roles::GUARD)->first();
            $created = false;

            if (! $role) {
                $role = Role::create(['name' => $name, 'guard_name' => Roles::GUARD]);
                $created = true;
            }

            $role->forceFill(['level' => Roles::LEVELS[$name], 'description' => Roles::DESCRIPTIONS[$name]])->save();

            $defaults = Roles::defaults()[$name];

            if ($name === Roles::SUPER_ADMIN) {
                $role->syncPermissions(Permission::where('guard_name', Roles::GUARD)->get());
            } elseif ($created) {
                $role->syncPermissions($defaults);
            } else {
                // Existing roles keep their configuration; only the new
                // permissions they are meant to have by default are added.
                $role->givePermissionTo(array_values(array_intersect(self::NEW_PERMISSIONS, $defaults)));
            }
        }

        App::make(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $table = config('permission.table_names.roles', 'roles');

        Schema::table($table, function (Blueprint $t) use ($table) {
            foreach (['description', 'level'] as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $t->dropColumn($column);
                }
            }
        });
    }
};
