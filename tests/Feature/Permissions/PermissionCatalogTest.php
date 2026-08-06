<?php

namespace Tests\Feature\Permissions;

use App\Support\Permissions;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Guardián del catálogo de permisos (Spec 0002).
 *
 * La lista de abajo se escribe a mano a propósito: es la expectativa de la spec,
 * independiente de `App\Support\Permissions`. Si alguien añade un permiso al
 * catálogo de la app sin pasar por aquí (o al revés), la prueba lo detecta.
 */
class PermissionCatalogTest extends TestCase
{
    /**
     * Catálogo canónico: todo en inglés, agrupado por módulo.
     *
     * @var array<string, array<int, string>>
     */
    private const CATALOGO = [
        'users' => ['view_users', 'create_users', 'edit_users', 'delete_users'],
        'meetings' => ['view_meetings', 'create_meetings', 'edit_meetings', 'delete_meetings'],
        'campaigns' => ['view_campaigns', 'create_campaigns', 'edit_campaigns', 'delete_campaigns'],
        'commitments' => ['view_commitments', 'create_commitments', 'edit_commitments', 'delete_commitments'],
        'resources' => ['view_resources', 'create_resources', 'edit_resources', 'delete_resources'],
        'voters' => ['view_voters'],
        'calls' => ['view_calls'],
        'contacts' => ['view_contacts'],
        'events' => ['view_events'],
        'liaisons' => ['manage_liaisons'],
        'landing' => ['manage_landingpage'],
        'reports' => ['view_reports'],
        'audits' => ['view_audits'],
        'dashboard' => ['view_comovamos', 'view_dashboardmap'],
    ];

    /**
     * Nombres retirados por la Spec 0002. No deben quedar en la base ni en el
     * código (el grep del código lo cubre `PermissionNamingTest`).
     */
    private const RETIRADOS = ['ver_electores', 'gestion_enlaces'];

    public function test_el_catalogo_de_la_aplicacion_coincide_con_el_esperado(): void
    {
        $this->assertSame(
            self::CATALOGO,
            Permissions::byModule(),
            'App\Support\Permissions divergió del catálogo de la Spec 0002.'
        );
    }

    public function test_el_seeder_crea_todos_los_permisos_del_catalogo(): void
    {
        $sembrados = Permission::where('guard_name', 'api')->pluck('name')->all();

        $faltantes = array_values(array_diff($this->catalogoPlano(), $sembrados));

        $this->assertSame(
            [],
            $faltantes,
            'Permisos del catálogo que el seeder no crea: '.implode(', ', $faltantes)
        );
    }

    public function test_no_hay_permisos_fuera_del_catalogo(): void
    {
        $sombrantes = array_values(array_diff(
            Permission::where('guard_name', 'api')->pluck('name')->all(),
            $this->catalogoPlano()
        ));

        $this->assertSame(
            [],
            $sombrantes,
            'Permisos sembrados que no están en el catálogo: '.implode(', ', $sombrantes)
        );
    }

    public function test_no_queda_ningun_permiso_con_nombre_retirado(): void
    {
        foreach (self::RETIRADOS as $viejo) {
            $this->assertDatabaseMissing('permissions', ['name' => $viejo]);
        }
    }

    public function test_todos_los_permisos_usan_el_guard_api(): void
    {
        $otrosGuards = Permission::where('guard_name', '!=', 'api')
            ->pluck('name', 'guard_name')
            ->all();

        $this->assertSame(
            [],
            $otrosGuards,
            'La API usa el guard `api`; un permiso con otro guard nunca se aplica.'
        );
    }

    public function test_el_rol_admin_tiene_todo_el_catalogo(): void
    {
        $admin = Role::where('name', 'admin')->where('guard_name', 'api')->first();

        $this->assertNotNull($admin, 'Debe existir el rol plantilla admin.');

        $faltantes = array_values(array_diff(
            $this->catalogoPlano(),
            $admin->permissions->pluck('name')->all()
        ));

        $this->assertSame(
            [],
            $faltantes,
            'El rol admin debe tener todo el catálogo. Le faltan: '.implode(', ', $faltantes)
        );
    }

    public function test_los_roles_plantilla_existen_con_guard_api(): void
    {
        foreach (['admin', 'coordinator', 'operator', 'viewer'] as $rol) {
            $this->assertDatabaseHas('roles', [
                'name' => $rol,
                'guard_name' => 'api',
                'tenant_id' => null,
            ]);
        }
    }

    public function test_el_seeder_es_idempotente(): void
    {
        // El setUp ya lo corrió una vez.
        $permisosAntes = Permission::count();
        $rolesAntes = Role::count();
        $asignacionesAntes = DB::table('role_has_permissions')->count();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame($permisosAntes, Permission::count(), 'El seeder duplicó permisos.');
        $this->assertSame($rolesAntes, Role::count(), 'El seeder duplicó roles.');
        $this->assertSame(
            $asignacionesAntes,
            DB::table('role_has_permissions')->count(),
            'El seeder duplicó asignaciones rol→permiso.'
        );
    }

    /**
     * @return array<int, string>
     */
    private function catalogoPlano(): array
    {
        return array_merge(...array_values(self::CATALOGO));
    }
}
