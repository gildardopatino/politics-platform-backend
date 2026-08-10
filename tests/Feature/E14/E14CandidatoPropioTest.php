<?php

namespace Tests\Feature\E14;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * El candidato propio de la campaña (Spec 0062 · Parte 0).
 *
 * Sin él el escrutinio se consolida pero no se cruza: «registrados vs votos»
 * necesita saber **de quién** son los votos, y eso es una fila del E-14
 * identificada por su número de tarjetón.
 *
 * La regla que se prueba aquí es la de los dos momentos: antes de la primera acta
 * el número se escribe a ciegas (se sabe semanas antes), y en cuanto hay catálogo
 * tiene que existir en él.
 */
class E14CandidatoPropioTest extends TestCase
{
    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create();
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

    // ---------------------------------------------------------- escritura

    public function test_se_puede_fijar_el_candidato_antes_de_cargar_actas(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        // El número del tarjetón se sabe semanas antes de que haya un acta.
        $this->putJson("/api/v1/e14/eventos/{$evento->id}/candidato-propio", [
            'numero' => 7,
            'nombre' => 'MIGUEL ALCALDE',
            'agrupacion' => 'MOVIMIENTO INDEPENDIENTE',
        ])->assertStatus(200)
            ->assertJsonPath('data.candidato_propio.numero', 7)
            ->assertJsonPath('data.candidato_propio.nombre', 'MIGUEL ALCALDE')
            ->assertJsonPath('data.candidato_propio.agrupacion', 'MOVIMIENTO INDEPENDIENTE');

        $this->assertDatabaseHas('electoral_events', [
            'id' => $evento->id,
            'candidato_propio_numero' => 7,
            'candidato_propio_nombre' => 'MIGUEL ALCALDE',
        ]);
    }

    public function test_con_actas_cargadas_el_numero_debe_estar_en_el_tarjeton(): void
    {
        $this->operador();
        $this->cargarActa();

        $evento = ElectoralEvent::first();

        $this->putJson("/api/v1/e14/eventos/{$evento->id}/candidato-propio", ['numero' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero');

        $this->assertDatabaseHas('electoral_events', [
            'id' => $evento->id,
            'candidato_propio_numero' => null,
        ]);
    }

    public function test_elegirlo_del_catalogo_completa_nombre_y_agrupacion(): void
    {
        $this->operador();
        $this->cargarActa();

        $evento = ElectoralEvent::first();

        // La pantalla manda solo el número: lo que el acta dice del candidato es
        // más fiable que lo que alguien teclee.
        $this->putJson("/api/v1/e14/eventos/{$evento->id}/candidato-propio", ['numero' => 2])
            ->assertStatus(200)
            ->assertJsonPath('data.candidato_propio.numero', 2)
            ->assertJsonPath('data.candidato_propio.nombre', 'JOHANA ARANDA');
    }

    public function test_se_puede_quitar_el_candidato_propio(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        $this->putJson("/api/v1/e14/eventos/{$evento->id}/candidato-propio", [
            'numero' => 7,
            'nombre' => 'MIGUEL ALCALDE',
        ])->assertStatus(200);

        $this->putJson("/api/v1/e14/eventos/{$evento->id}/candidato-propio", ['numero' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.candidato_propio.numero', null)
            // Borrar el número borra la ficha entera: un nombre suelto de un
            // candidato que ya no se cruza solo confunde.
            ->assertJsonPath('data.candidato_propio.nombre', null);
    }

    public function test_el_numero_es_obligatorio_en_la_peticion(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant);

        // Sin `numero` no se sabe si se quiere fijar o borrar: 422 antes que
        // adivinar.
        $this->putJson("/api/v1/e14/eventos/{$evento->id}/candidato-propio", ['nombre' => 'X'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('numero');
    }

    // ---------------------------------------------- permisos y aislamiento

    public function test_configurarlo_exige_manage_e14(): void
    {
        $tenant = $this->operador([Permissions::VIEW_E14]);
        $evento = $this->evento($tenant);

        $this->putJson("/api/v1/e14/eventos/{$evento->id}/candidato-propio", ['numero' => 7])
            ->assertStatus(403);
    }

    public function test_verlo_exige_view_e14(): void
    {
        $this->operador([Permissions::MANAGE_E14]);

        $this->getJson('/api/v1/e14/eventos')->assertStatus(403);
    }

    public function test_no_se_ve_ni_se_configura_la_eleccion_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create();
        $eventoAjeno = $this->evento($ajeno, 'Alcaldía ajena');

        $propio = $this->operador();
        $this->evento($propio);

        $this->getJson('/api/v1/e14/eventos')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/e14/eventos/{$eventoAjeno->id}/candidato-propio", ['numero' => 7])
            ->assertStatus(404);
    }
}
