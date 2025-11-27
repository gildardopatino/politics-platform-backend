<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Crear el permiso
        Permission::create(['name' => 'gestion_enlaces']);
        
        // Asignar el permiso al rol admin (si existe)
        $adminRole = Role::where('name', 'admin')->first();
        if ($adminRole) {
            $adminRole->givePermissionTo('gestion_enlaces');
        }
        
        // Asignar el permiso al rol coordinator (si existe)
        $coordinatorRole = Role::where('name', 'coordinator')->first();
        if ($coordinatorRole) {
            $coordinatorRole->givePermissionTo('gestion_enlaces');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Eliminar el permiso
        $permission = Permission::where('name', 'gestion_enlaces')->first();
        if ($permission) {
            $permission->delete();
        }
    }
};
