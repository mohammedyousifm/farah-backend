<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Admin RBAC from config('farah.admin_modules') / config('farah.admin_roles'). Idempotent. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = [];
        foreach (config('farah.admin_modules') as $module) {
            foreach (['view', 'manage'] as $ability) {
                $all[] = Permission::findOrCreate("{$module}.{$ability}", 'web')->name;
            }
        }

        foreach (config('farah.admin_roles') as $roleName => $grants) {
            $role = Role::findOrCreate($roleName, 'web');

            if ($grants === '*') {
                $role->syncPermissions($all);

                continue;
            }

            // "orders" grants view+manage; "orders.view" grants only view.
            $permissions = collect($grants)->flatMap(fn (string $g) => str_contains($g, '.') ? [$g] : ["{$g}.view", "{$g}.manage"]);
            $role->syncPermissions($permissions->all());
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
