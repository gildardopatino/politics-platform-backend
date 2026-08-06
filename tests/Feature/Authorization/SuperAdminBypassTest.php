<?php

namespace Tests\Feature\Authorization;

use App\Models\Tenant;
use Tests\TestCase;

/**
 * El super admin global no pertenece a ningún tenant, así que no tiene roles ni
 * permisos asignados: sin un bypass explícito, cualquier ruta con `permission:`
 * o `role:` le respondería 403 (Spec 0005, RF-4).
 */
class SuperAdminBypassTest extends TestCase
{
    public function test_el_super_admin_pasa_una_ruta_protegida_por_permiso(): void
    {
        Tenant::factory()->create();

        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertFalse(
            $superAdmin->hasPermissionTo('view_commitments'),
            'El super admin no tiene el permiso asignado: debe pasar por el bypass, no por tenerlo.'
        );

        $this->getJson('/api/v1/commitments/overdue')->assertStatus(200);
    }

    public function test_el_super_admin_supera_cualquier_comprobacion_del_gate(): void
    {
        $superAdmin = $this->actingAsSuperAdmin();

        $this->assertTrue($superAdmin->can('view_commitments'));
        $this->assertTrue($superAdmin->can('manage_landingpage'));
        $this->assertTrue($superAdmin->can('un_permiso_que_no_existe'));
    }

    public function test_el_bypass_no_alcanza_a_los_usuarios_de_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->assertFalse($user->can('view_commitments'));

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/commitments/overdue')
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }
}
