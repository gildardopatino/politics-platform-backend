<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            'view_users',
            'create_users',
            'edit_users',
            'delete_users',
            'view_meetings',
            'create_meetings',
            'edit_meetings',
            'delete_meetings',
            'view_campaigns',
            'create_campaigns',
            'edit_campaigns',
            'delete_campaigns',
            'view_commitments',
            'create_commitments',
            'edit_commitments',
            'delete_commitments',
            'view_resources',
            'create_resources',
            'edit_resources',
            'delete_resources',
            'view_reports',
            'gestion_enlaces',
        ];

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::create(['name' => $permission]);
        }

        // Create roles
        $adminRole = \Spatie\Permission\Models\Role::create(['name' => 'admin']);
        $adminRole->givePermissionTo(\Spatie\Permission\Models\Permission::all());

        $coordinatorRole = \Spatie\Permission\Models\Role::create(['name' => 'coordinator']);
        $coordinatorRole->givePermissionTo([
            'view_users', 'create_users', 'edit_users',
            'view_meetings', 'create_meetings', 'edit_meetings',
            'view_commitments', 'create_commitments', 'edit_commitments',
            'view_resources', 'view_reports',
        ]);

        $operatorRole = \Spatie\Permission\Models\Role::create(['name' => 'operator']);
        $operatorRole->givePermissionTo([
            'view_users', 'view_meetings', 'create_meetings',
            'view_commitments', 'view_resources',
        ]);

        $viewerRole = \Spatie\Permission\Models\Role::create(['name' => 'viewer']);
        $viewerRole->givePermissionTo([
            'view_users', 'view_meetings', 'view_commitments',
            'view_resources', 'view_reports',
        ]);
    }
}
