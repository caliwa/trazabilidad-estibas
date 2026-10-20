<?php

namespace Database\Seeders;

use App\Enums\Permission;
use App\Enums\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syncs roles and permissions with the Role and Permission enums, which are
 * the source of truth. Safe to run on every deploy.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, 'web');
        }

        // Permissions that no longer exist (transfers.request went away when the transfer became part of the count).
        PermissionModel::query()->where('guard_name', 'web')->whereNotIn('name', array_map(fn (Permission $p) => $p->value, Permission::cases()))->delete();

        // The registrar keeps the permission list in memory; reload it so the new rows are visible.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Role::cases() as $role) {
            RoleModel::findOrCreate($role->value, 'web')->syncPermissions(
                array_map(fn (Permission $permission) => $permission->value, $role->permissions()),
            );
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
