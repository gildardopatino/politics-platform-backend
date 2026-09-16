<?php

namespace Tests\Feature\VoterProfiles;

use App\Models\Tenant;
use App\Scopes\TenantScope;
use Database\Seeders\VoterProfilePermissionsSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los tenants que ya existían también reciben los permisos de la Spec 0094.
 *
 * El clonado de roles solo ocurre al crear el tenant, así que sin este backfill
 * el módulo sería invisible para todas las campañas ya dadas de alta.
 */
class VoterProfilePermissionsBackfillTest extends TestCase
{
    public function test_los_roles_de_un_tenant_existente_reciben_los_permisos_nuevos(): void
    {
        $tenant = Tenant::factory()->create();

        // Un tenant «viejo»: sus roles se clonaron antes de que existiera la 0094.
        $admin = $this->rolDelTenant($tenant, 'admin');
        $operator = $this->rolDelTenant($tenant, 'operator');
        $viewer = $this->rolDelTenant($tenant, 'viewer');

        $this->seed(VoterProfilePermissionsSeeder::class);

        $this->assertTrue($admin->fresh()->hasPermissionTo('view_voter_profiles'));
        $this->assertTrue($admin->fresh()->hasPermissionTo('manage_voter_profiles'));

        $this->assertTrue($operator->fresh()->hasPermissionTo('manage_voter_profiles'));

        // `viewer` mira la bolsa, no la escribe.
        $this->assertTrue($viewer->fresh()->hasPermissionTo('view_voter_profiles'));
        $this->assertFalse($viewer->fresh()->hasPermissionTo('manage_voter_profiles'));
    }

    public function test_correrlo_dos_veces_no_duplica_asignaciones(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = $this->rolDelTenant($tenant, 'admin');

        $this->seed(VoterProfilePermissionsSeeder::class);

        $antes = $admin->fresh()->permissions->count();

        $this->seed(VoterProfilePermissionsSeeder::class);

        $this->assertSame($antes, $admin->fresh()->permissions->count());
    }

    public function test_no_inventa_permisos_fuera_del_catalogo(): void
    {
        $this->seed(VoterProfilePermissionsSeeder::class);

        $fuera = array_values(array_diff(
            Permission::where('guard_name', 'api')->pluck('name')->all(),
            \App\Support\Permissions::all()
        ));

        $this->assertSame([], $fuera);
    }

    private function rolDelTenant(Tenant $tenant, string $nombre): Role
    {
        return Role::withoutGlobalScope(TenantScope::class)->create([
            'name' => $nombre,
            'guard_name' => 'api',
            'tenant_id' => $tenant->id,
        ]);
    }
}
