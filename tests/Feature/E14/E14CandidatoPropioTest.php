<?php

namespace Tests\Feature\E14;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Las elecciones del tenant y su candidato, en lectura (Specs 0062 y 0080).
 *
 * `GET /eventos` es de donde el cruce y el consolidado sacan la lista con la que
 * elegir de qué elección se consulta, y de paso muestra el candidato ya fijado y
 * el tarjetón de cada una.
 *
 * **Configurar** el candidato ya no se hace aquí: la 0080 retiró el
 * `PUT /eventos/{id}/candidato-propio` —era la única forma de ponerlo en una
 * elección que no es la del cargo de la campaña— y lo movió a
 * `PUT /e14/candidato`, uno por tenant. Esa cobertura vive en
 * {@see E14CandidatoDelTenantTest}.
 */
class E14CandidatoPropioTest extends TestCase
{
    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    private function evento(Tenant $tenant, string $nombre = 'Alcaldía'): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => 'alcaldia',
            'nombre' => $nombre,
            'fecha' => '2027-10-31',
        ]);
    }

    /** Un acta que cuadra: 50 + 30 + 19 + 4 + 4 + 4 = 111. */
    private function cargarActa(array $cambios = []): void
    {
        $this->postJson('/api/v1/e14/actas', array_replace([
            'tipo' => 'alcaldia',
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'suma_declarada' => 111,
            'votos_urna' => 111,
            'votantes_e11' => 111,
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ], $cambios))->assertStatus(201);
    }

    // ------------------------------------------------------------- lectura

    public function test_una_eleccion_recien_creada_no_tiene_candidato_propio(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/eventos')
            ->assertStatus(200)
            ->assertJsonPath('data.0.nombre', 'Alcaldía')
            ->assertJsonPath('data.0.candidato_propio.numero', null)
            ->assertJsonPath('data.0.tiene_actas', false)
            ->assertJsonPath('data.0.candidatos', []);
    }

    public function test_el_tarjeton_viaja_con_la_eleccion(): void
    {
        $this->operador();
        $this->cargarActa();

        $this->getJson('/api/v1/e14/eventos')
            ->assertStatus(200)
            ->assertJsonPath('data.0.tiene_actas', true)
            ->assertJsonPath('data.0.candidatos.0.numero', 1)
            ->assertJsonPath('data.0.candidatos.1.nombre', 'JOHANA ARANDA')
            ->assertJsonCount(3, 'data.0.candidatos');
    }

    public function test_el_candidato_fijado_se_ve_en_el_listado(): void
    {
        $this->operador();
        $this->cargarActa();

        $this->putJson('/api/v1/e14/candidato', ['numero' => 2])->assertStatus(200);

        $this->getJson('/api/v1/e14/eventos')
            ->assertStatus(200)
            ->assertJsonPath('data.0.candidato_propio.numero', 2);
    }

    // ---------------------------------------------- permisos y aislamiento

    public function test_verlo_exige_view_e14(): void
    {
        $this->operador([Permissions::MANAGE_E14]);

        $this->getJson('/api/v1/e14/eventos')->assertStatus(403);
    }

    public function test_no_se_ve_la_eleccion_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);
        $this->evento($ajeno, 'Alcaldía ajena');

        $propio = $this->operador();
        $this->evento($propio);

        $this->getJson('/api/v1/e14/eventos')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nombre', 'Alcaldía');
    }
}
