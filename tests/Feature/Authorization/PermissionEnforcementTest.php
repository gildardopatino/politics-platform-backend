<?php

namespace Tests\Feature\Authorization;

use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * Enforcement de permisos en el backend.
 *
 * Este archivo nació en la Spec 0001 como **caracterización**: documentaba que
 * `routes/api.php` no usaba el middleware `permission:` en ninguna ruta, así que
 * el gating de permisos era solo del frontend y cualquier usuario autenticado
 * del tenant llegaba por API a endpoints para los que la UI le ocultaba el
 * botón. Fijaba el 200 para que el cambio fuera visible.
 *
 * La Spec 0005 cerró ese hueco: ahora exige 403 sin permiso y 200 con permiso.
 *
 * @see RoutePermissionEnforcementTest  matriz por módulo
 * @see SuperAdminBypassTest            bypass del super admin
 */
class PermissionEnforcementTest extends TestCase
{
    public function test_sin_view_commitments_el_listado_responde_403(): void
    {
        $tenant = Tenant::factory()->create();
        Commitment::factory()->forTenant($tenant)->create();

        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->assertFalse($user->can('view_commitments'));

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/commitments')
            ->assertStatus(403)
            ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');
    }

    public function test_con_view_commitments_el_listado_responde_200(): void
    {
        $tenant = Tenant::factory()->create();
        Commitment::factory()->forTenant($tenant)->create();

        [$user, $token] = $this->createTenantWithUser(['view_commitments'], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/commitments')
            ->assertStatus(200);
    }

    public function test_sin_view_commitments_overdue_responde_403(): void
    {
        $tenant = Tenant::factory()->create();
        Commitment::factory()->forTenant($tenant)->overdue()->create();

        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/commitments/overdue')
            ->assertStatus(403);
    }

    public function test_con_view_commitments_overdue_responde_200(): void
    {
        $tenant = Tenant::factory()->create();
        Commitment::factory()->forTenant($tenant)->overdue()->create();

        [$user, $token] = $this->createTenantWithUser(['view_commitments'], $tenant);

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/commitments/overdue')
            ->assertStatus(200);
    }

    public function test_el_enforcement_no_es_exclusivo_de_commitments(): void
    {
        $tenant = Tenant::factory()->create();
        Meeting::factory()->forTenant($tenant)->create();

        [$sinPermiso, $tokenSin] = $this->createTenantWithUser([], $tenant);
        [$conPermiso, $tokenCon] = $this->createTenantWithUser(['view_meetings'], $tenant);

        $this->actingAsTenantUser($sinPermiso, $tokenSin)
            ->getJson('/api/v1/meetings')
            ->assertStatus(403);

        $this->actingAsTenantUser($conPermiso, $tokenCon)
            ->getJson('/api/v1/meetings')
            ->assertStatus(200);
    }

    public function test_el_permiso_se_asigna_correctamente_al_usuario(): void
    {
        $tenant = Tenant::factory()->create();

        [$conPermiso] = $this->createTenantWithUser(['view_commitments'], $tenant);
        [$sinPermiso] = $this->createTenantWithUser([], $tenant);

        $this->assertTrue($conPermiso->can('view_commitments'));
        $this->assertFalse($sinPermiso->can('view_commitments'));
    }
}
