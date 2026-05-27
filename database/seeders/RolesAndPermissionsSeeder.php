<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Idempotent: uses firstOrCreate + syncPermissions so it is safe to run on
     * every container boot. These are the GLOBAL role/permission templates
     * (guard "api", tenant_id null); per-tenant role sets are cloned from these
     * when a tenant is created (see TenantController).
     */
    public function run(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $guard = 'api';

        $permissions = [
            'view_users', 'create_users', 'edit_users', 'delete_users',
            'view_meetings', 'create_meetings', 'edit_meetings', 'delete_meetings',
            'view_campaigns', 'create_campaigns', 'edit_campaigns', 'delete_campaigns',
            'view_commitments', 'create_commitments', 'edit_commitments', 'delete_commitments',
            'view_resources', 'create_resources', 'edit_resources', 'delete_resources',
            'view_reports', 'gestion_enlaces',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => $guard]);
        $admin->syncPermissions(Permission::where('guard_name', $guard)->get());

        $coordinator = Role::firstOrCreate(['name' => 'coordinator', 'guard_name' => $guard]);
        $coordinator->syncPermissions([
            'view_users', 'create_users', 'edit_users',
            'view_meetings', 'create_meetings', 'edit_meetings',
            'view_commitments', 'create_commitments', 'edit_commitments',
            'view_resources', 'view_reports',
        ]);

        $operator = Role::firstOrCreate(['name' => 'operator', 'guard_name' => $guard]);
        $operator->syncPermissions([
            'view_users', 'view_meetings', 'create_meetings',
            'view_commitments', 'view_resources',
        ]);

        $viewer = Role::firstOrCreate(['name' => 'viewer', 'guard_name' => $guard]);
        $viewer->syncPermissions([
            'view_users', 'view_meetings', 'view_commitments',
            'view_resources', 'view_reports',
        ]);
    }
}
