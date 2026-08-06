<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Idempotente: usa firstOrCreate + syncPermissions, así que es seguro
     * correrlo en cada arranque del contenedor. Siembra las plantillas GLOBALES
     * de roles/permisos (guard `api`, tenant_id null); los conjuntos por tenant
     * se clonan de estas al crear un tenant (ver TenantController).
     *
     * La lista de nombres vive en App\Support\Permissions (Spec 0002): fuente
     * única, alineada con src/constants/permissions.ts del frontend.
     */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = Permissions::GUARD;

        foreach (Permissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => $guard]);
        }

        foreach (Permissions::byRole() as $nombre => $permisos) {
            $role = Role::firstOrCreate(['name' => $nombre, 'guard_name' => $guard]);
            $role->syncPermissions($permisos);
        }

        // Los permisos recién creados no pueden quedar en la cache anterior.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
