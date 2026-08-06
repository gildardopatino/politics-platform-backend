<?php

namespace Tests\Feature\Authorization;

use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * CARACTERIZACIÓN — documenta un comportamiento actual DEFECTUOSO, no deseado.
 *
 * Hallazgo de la Spec 0001: el backend **no aplica enforcement de permisos**.
 * `routes/api.php` no usa el middleware `permission:` en ninguna ruta; las
 * únicas comprobaciones reales son `AuditController` (`can('view_audits')`) y
 * dos policies de `VoterController`. En `GeographicContactController` la línea
 * `$this->middleware('permission:manage_liaisons')` está comentada.
 *
 * Consecuencia: los permisos hoy solo los aplica el frontend (`ProtectedRoute`),
 * así que cualquier usuario autenticado del tenant puede llamar por API a
 * endpoints para los que la UI le oculta el botón.
 *
 * Estas pruebas fijan el 200 actual para que el cambio sea visible: cuando se
 * implemente el enforcement (spec dedicada del backlog, posterior a 0002/0003)
 * fallarán y habrá que cambiarlas a esperar 403.
 *
 * @see \Tests\Feature\Commitments\CommitmentOverdueTest
 */
class PermissionEnforcementCharacterizationTest extends TestCase
{
    public function test_caracteriza_que_sin_view_commitments_el_listado_responde_200(): void
    {
        $tenant = Tenant::factory()->create();
        Commitment::factory()->forTenant($tenant)->create();

        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->assertFalse($user->can('view_commitments'), 'El usuario no debe tener el permiso.');

        // Esperado por la spec original: 403. Comportamiento actual: 200.
        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/commitments')
            ->assertStatus(200);
    }

    /**
     * Ya NO es caracterización: `/commitments/overdue` fue la primera ruta que la
     * Spec 0005 puso bajo `permission:`. Las otras dos siguen documentando el
     * hueco hasta que la Fase 2 aplique el mapa completo.
     */
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

    public function test_caracteriza_que_el_hueco_no_es_exclusivo_de_commitments(): void
    {
        $tenant = Tenant::factory()->create();
        Meeting::factory()->forTenant($tenant)->create();

        [$user, $token] = $this->createTenantWithUser([], $tenant);

        $this->assertFalse($user->can('view_meetings'));

        $this->actingAsTenantUser($user, $token)
            ->getJson('/api/v1/meetings')
            ->assertStatus(200);
    }

    public function test_el_permiso_si_existe_y_se_asigna_correctamente_al_usuario(): void
    {
        // Contraste: el sistema de permisos funciona; lo que falta es aplicarlo
        // en las rutas. Con esto queda claro que el 200 de arriba no se debe a
        // que el permiso no exista o el harness no lo asigne.
        $tenant = Tenant::factory()->create();

        [$conPermiso] = $this->createTenantWithUser(['view_commitments'], $tenant);
        [$sinPermiso] = $this->createTenantWithUser([], $tenant);

        $this->assertTrue($conPermiso->can('view_commitments'));
        $this->assertFalse($sinPermiso->can('view_commitments'));
    }
}
