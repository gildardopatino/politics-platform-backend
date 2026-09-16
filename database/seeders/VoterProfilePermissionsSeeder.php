<?php

namespace Database\Seeders;

use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reparte los permisos de la Spec 0094 entre los tenants que ya existen.
 *
 * `RolesAndPermissionsSeeder` siembra las plantillas **globales** (tenant_id
 * null), y `TenantProvisioningService` las clona al **crear** un tenant. Los
 * tenants que ya estaban creados no vuelven a pasar por ese clonado, así que un
 * permiso nuevo no les llega nunca: el módulo queda invisible para todos salvo
 * los que se den de alta de aquí en adelante.
 *
 * Mismo patrón que `AuditPermissionSeeder` en su día. Es un backfill, no parte
 * del arranque: por eso no está en `DatabaseSeeder` —una base limpia ya sale
 * bien del seeder de roles más el provisioning.
 *
 * Idempotente (Art. VIII): solo asigna lo que falta.
 */
class VoterProfilePermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $nuevos = [Permissions::VIEW_VOTER_PROFILES, Permissions::MANAGE_VOTER_PROFILES];

        foreach ($nuevos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso, 'guard_name' => Permissions::GUARD]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $asignados = 0;

        foreach (Permissions::byRole() as $nombre => $permisosDelRol) {
            $aplican = array_values(array_intersect($nuevos, $permisosDelRol));

            if ($aplican === []) {
                continue;
            }

            // Sin `current_tenant_id` ligado (consola) el scope no filtra, pero se
            // quita de forma explícita: este seeder cruza tenants a propósito y el
            // Artículo III pide que eso se vea en el código.
            $roles = Role::withoutGlobalScope(TenantScope::class)
                ->where('name', $nombre)
                ->where('guard_name', Permissions::GUARD)
                ->get();

            foreach ($roles as $role) {
                $faltantes = array_values(array_filter(
                    $aplican,
                    fn (string $permiso) => ! $role->hasPermissionTo($permiso)
                ));

                if ($faltantes === []) {
                    continue;
                }

                $role->givePermissionTo($faltantes);
                $asignados += count($faltantes);

                $this->command?->info(
                    "Rol {$nombre} (tenant_id: ".($role->tenant_id ?? 'global').'): +'.implode(', ', $faltantes)
                );
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info("Asignaciones nuevas: {$asignados}.");
    }
}
