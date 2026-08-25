<?php

namespace Tests\Feature\E14;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Tests\TestCase;

/**
 * El listado de elecciones, acotado al tipo de la campaña (Spec 0093 · Parte D).
 *
 * La 0093 blindó el tenant a **una** elección: la carga, el cruce, el
 * consolidado y las estadísticas derivan el tipo del `tipo_cargo` y ya no
 * aceptan el del cliente. `GET /e14/eventos` se quedó fuera de ese barrido y
 * seguía listando jornadas de otro tipo —viejas o de prueba—; el selector de
 * evento las ofrecía, quien las elegía recibía un 422 («esa elección es de…») o
 * una tabla vacía, y parecía que el sistema había perdido los datos.
 */
class E14EventosDelTenantTest extends TestCase
{
    private const RUTA = '/api/v1/e14/eventos';

    private function evento(Tenant $tenant, string $tipo, string $nombre, ?string $fecha = '2027-10-31'): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => $nombre,
            'fecha' => $fecha,
        ]);
    }

    public function test_solo_lista_las_elecciones_del_tipo_de_la_campana(): void
    {
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);
        [$user, $token] = $this->createTenantWithUser(['view_e14'], $tenant);

        $suya = $this->evento($tenant, 'alcaldia', 'Alcaldía 2027');
        $this->evento($tenant, 'concejo', 'Concejo 2027');
        $this->evento($tenant, 'gobernacion', 'Gobernación 2027');

        $respuesta = $this->actingAsTenantUser($user, $token)->getJson(self::RUTA);

        $respuesta->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $suya->id)
            ->assertJsonPath('data.0.tipo', 'alcaldia');
    }

    public function test_una_campana_de_corporacion_ve_la_suya_y_no_la_uninominal(): void
    {
        $tenant = Tenant::factory()->corporacion()->create();
        [$user, $token] = $this->createTenantWithUser(['view_e14'], $tenant);

        $concejo = $this->evento($tenant, 'concejo', 'Concejo 2027');
        $this->evento($tenant, 'alcaldia', 'Alcaldía 2027');

        $this->actingAsTenantUser($user, $token)
            ->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $concejo->id);
    }

    public function test_una_campana_sin_eleccion_recibe_una_lista_vacia_y_no_un_error(): void
    {
        // `Otro` no es cargo de elección popular: no hay escrutinio. Un 422 aquí
        // convertiría un menú que no debería estar en un error rojo.
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Otro']);
        [$user, $token] = $this->createTenantWithUser(['view_e14'], $tenant);

        $this->evento($tenant, 'alcaldia', 'Alcaldía 2027');

        $this->actingAsTenantUser($user, $token)
            ->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_no_se_ven_las_elecciones_de_otra_campana(): void
    {
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);
        [$user, $token] = $this->createTenantWithUser(['view_e14'], $tenant);

        $ajeno = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);
        // Mismo tipo que la campaña de arriba: si el filtro nuevo se comiera el
        // aislamiento, esta fila aparecería.
        $this->evento($ajeno, 'alcaldia', 'Alcaldía de otra campaña');

        $propia = $this->evento($tenant, 'alcaldia', 'Alcaldía 2027');

        $this->actingAsTenantUser($user, $token)
            ->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $propia->id);
    }
}
