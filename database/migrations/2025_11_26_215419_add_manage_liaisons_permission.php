<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * La Spec 0002 renombró este permiso al inglés y lo incorporó al catálogo
     * canónico (App\Support\Permissions). Como no hay datos de producción se
     * edita esta migración en vez de añadir una de renombrado.
     *
     * En una base limpia los roles todavía no existen: quien siembra el catálogo
     * completo y las asignaciones es RolesAndPermissionsSeeder. Esto queda como
     * red de seguridad idempotente para bases ya migradas.
     */
    public function up(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => Permissions::MANAGE_LIAISONS,
            'guard_name' => Permissions::GUARD,
        ]);

        foreach (['admin', 'coordinator'] as $nombre) {
            $role = Role::where('name', $nombre)
                ->where('guard_name', Permissions::GUARD)
                ->first();

            $role?->givePermissionTo($permission);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::where('name', Permissions::MANAGE_LIAISONS)
            ->where('guard_name', Permissions::GUARD)
            ->delete();
    }
};
