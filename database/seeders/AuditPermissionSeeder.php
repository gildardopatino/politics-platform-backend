<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AuditPermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Crear el permiso view_audits si no existe
        $permission = Permission::firstOrCreate([
            'name' => 'view_audits',
            'guard_name' => 'api'
        ]);

        $this->command->info('Permiso view_audits creado/verificado.');

        // Asignar a todos los roles admin en todos los tenants
        $adminRoles = Role::where('name', 'admin')->get();

        foreach ($adminRoles as $role) {
            if (!$role->hasPermissionTo('view_audits')) {
                $role->givePermissionTo('view_audits');
                $this->command->info("Permiso asignado al rol admin (tenant_id: {$role->tenant_id})");
            } else {
                $this->command->info("El rol admin ya tiene el permiso (tenant_id: {$role->tenant_id})");
            }
        }

        $this->command->info("Total roles admin actualizados: {$adminRoles->count()}");
    }
}
