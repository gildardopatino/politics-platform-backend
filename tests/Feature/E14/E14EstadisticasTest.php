<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Estadísticas del escrutinio (Spec 0092 · Parte A).
 *
 * Una sola fuente para toda la exploración: la fila **de mesa** del cruce
 * (base, votos de mi candidato, déficit) enriquecida con lo que el acta sabe de
 * esa misma mesa (zona, urna, sufragantes). El frontend hace con eso los
 * roll-ups —departamento, municipio, zona, puesto— sin volver a preguntar.
 *
 * Las dos mitades de la fila **no miden lo mismo**, y de ahí sale casi toda la
 * prueba: `base`/`votos_candidato`/`deficit` son del candidato del tenant, y
 * `votos_urna`/`votantes_e11` son de la mesa entera —todos los candidatos—. Un
 * número junto al otro solo se puede leer sabiendo eso.
 */
class E14EstadisticasTest extends TestCase
{
    private const LUGAR = 'COLEGIO SAN SIMON';

    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    private function evento(Tenant $tenant, ?int $numero = 2): ElectoralEvent
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['tenant_id' => $tenant->id, 'tipo' => 'alcaldia', 'nombre' => 'Alcaldía'],
            ['fecha' => '2027-10-31'],
        );

        $evento->update([
            'candidato_propio_numero' => $numero,
            'candidato_propio_nombre' => $numero === null ? null : 'JOHANA ARANDA',
        ]);

        return $evento;
    }

    /** Acta que cuadra: 50 + 30 + 19 + 4 + 4 + 4 = 111. Mi candidato es el 2. */
    private function cargarActa(array $cambios = []): void
    {
        $this->postJson('/api/v1/e14/actas', array_replace([
            'tipo' => 'alcaldia',
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '005',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => self::LUGAR,
            'suma_declarada' => 111,
            'votos_urna' => 111,
            'votantes_e11' => 108,
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 50],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 30],
                ['numero' => 3, 'nombre' => 'RENSO GARCIA', 'votos' => 19],
            ],
        ], $cambios))->assertSuccessful();
    }

    private function votantes(Tenant $tenant, int $cuantos, array $atributos = []): void
    {
        Voter::factory()->count($cuantos)->forTenant($tenant)->create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::LUGAR,
            'mesa_votacion' => '5',
        ], $atributos));
    }

    private function puestoDelCatalogo(array $cambios = []): VotingPlace
    {
        return VotingPlace::create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::LUGAR,
        ], $cambios));
    }

    // ------------------------------------------------------- la fila de mesa

    public function test_cada_fila_trae_zona_urna_y_sufragantes_ademas_del_cruce(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id, 'mesa_votacion' => '5']);
        $this->cargarActa();
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/estadisticas?tipo=alcaldia')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.voting_place_id', $lugar->id)
            ->assertJsonPath('data.0.departamento', 'TOLIMA')
            ->assertJsonPath('data.0.municipio', 'IBAGUE')
            ->assertJsonPath('data.0.zona', '01')
            ->assertJsonPath('data.0.puesto', self::LUGAR)
            ->assertJsonPath('data.0.mesa', 5)
            // Del candidato del tenant.
            ->assertJsonPath('data.0.base', 50)
            ->assertJsonPath('data.0.votos_candidato', 30)
            ->assertJsonPath('data.0.deficit', 20)
            ->assertJsonPath('data.0.excedente', 0)
            ->assertJsonPath('data.0.tiene_acta', true)
            // De la mesa entera: todos los candidatos.
            ->assertJsonPath('data.0.votos_urna', 111)
            ->assertJsonPath('data.0.votantes_e11', 108)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.con_acta', 1);
    }

    public function test_la_mesa_sin_acta_va_en_cero_y_cuenta_en_el_denominador(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 40, ['voting_place_id' => $lugar->id, 'mesa_votacion' => '5']);
        // Base identificada en una mesa que todavía nadie escrutó.
        $this->votantes($tenant, 10, ['voting_place_id' => $lugar->id, 'mesa_votacion' => '6']);
        $this->cargarActa(['mesa' => '005']);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/estadisticas?tipo=alcaldia')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.mesa', 6)
            ->assertJsonPath('data.1.base', 10)
            ->assertJsonPath('data.1.tiene_acta', false)
            ->assertJsonPath('data.1.votos_urna', 0)
            ->assertJsonPath('data.1.votantes_e11', 0)
            ->assertJsonPath('data.1.zona', null)
            // La cobertura es 1 de 2: la mesa pendiente está en el denominador.
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.con_acta', 1);
    }

    public function test_dos_mesas_homonimas_de_puestos_distintos_no_se_funden(): void
    {
        $tenant = $this->operador();
        $sanSimon = $this->puestoDelCatalogo();
        $laPaz = $this->puestoDelCatalogo(['puesto_votacion' => 'ESCUELA LA PAZ']);

        $this->votantes($tenant, 20, ['voting_place_id' => $sanSimon->id, 'mesa_votacion' => '2']);
        $this->votantes($tenant, 70, [
            'voting_place_id' => $laPaz->id,
            'puesto_votacion' => 'ESCUELA LA PAZ',
            'mesa_votacion' => '2',
        ]);

        $this->cargarActa(['mesa' => '002']);
        $this->cargarActa([
            'mesa' => '002',
            'zona' => '02',
            'lugar' => 'ESCUELA LA PAZ',
            'votos_urna' => 42,
            'votantes_e11' => 41,
            'suma_declarada' => 42,
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'resultados' => [['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 42]],
        ]);
        $this->evento($tenant);

        // La mesa «002» existe en los dos puestos y son dos mesas distintas: el
        // `voting_place_id` es lo que las desambigua, no el número.
        $this->getJson('/api/v1/e14/estadisticas?tipo=alcaldia')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.puesto', self::LUGAR)
            ->assertJsonPath('data.0.voting_place_id', $sanSimon->id)
            ->assertJsonPath('data.0.mesa', 2)
            ->assertJsonPath('data.0.zona', '01')
            ->assertJsonPath('data.0.base', 20)
            ->assertJsonPath('data.0.votos_candidato', 30)
            ->assertJsonPath('data.0.votos_urna', 111)
            ->assertJsonPath('data.1.puesto', 'ESCUELA LA PAZ')
            ->assertJsonPath('data.1.voting_place_id', $laPaz->id)
            ->assertJsonPath('data.1.mesa', 2)
            ->assertJsonPath('data.1.zona', '02')
            ->assertJsonPath('data.1.base', 70)
            ->assertJsonPath('data.1.votos_candidato', 42)
            ->assertJsonPath('data.1.deficit', 28)
            ->assertJsonPath('data.1.votos_urna', 42)
            ->assertJsonPath('data.1.votantes_e11', 41);
    }

    public function test_el_acta_leida_que_no_cuadra_aporta_urna_pero_no_votos(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id, 'mesa_votacion' => '5']);
        // Declara 120 y las casillas suman 111: se leyó, pero no cuadra, así que
        // sus votos no juzgan a nadie. Su urna y sus sufragantes sí son un hecho
        // del acta, igual que en el desglose del consolidado.
        $this->cargarActa(['suma_declarada' => 120, 'votos_urna' => 120]);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/estadisticas?tipo=alcaldia')
            ->assertStatus(200)
            ->assertJsonPath('data.0.votos_candidato', 0)
            ->assertJsonPath('data.0.tiene_acta', false)
            ->assertJsonPath('data.0.votos_urna', 120)
            ->assertJsonPath('data.0.votantes_e11', 108)
            ->assertJsonPath('data.0.zona', '01')
            ->assertJsonPath('meta.con_acta', 0);
    }

    public function test_las_actas_de_la_cola_no_aportan_urna(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 20, ['voting_place_id' => $lugar->id, 'mesa_votacion' => '5']);
        $evento = $this->evento($tenant);

        // Un acta que nadie ha leído todavía no tiene nada que contar.
        E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => 'alcaldia',
            'estado' => E14Acta::ESTADO_PENDIENTE,
            'zona' => '01',
            'mesa' => '005',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => self::LUGAR,
            'voting_place_id' => $lugar->id,
            'votos_urna' => 111,
            'votantes_e11' => 108,
        ]);

        $this->getJson('/api/v1/e14/estadisticas?tipo=alcaldia')
            ->assertStatus(200)
            ->assertJsonPath('data.0.tiene_acta', false)
            ->assertJsonPath('data.0.votos_urna', 0)
            ->assertJsonPath('data.0.votantes_e11', 0)
            ->assertJsonPath('data.0.zona', null);
    }

    // ------------------------------------------------ parámetros y permisos

    public function test_el_tipo_ya_no_se_pide_porque_lo_pone_la_campana(): void
    {
        // Era obligatorio en la 0092: la página se abría eligiendo elección.
        // Desde la 0093 la campaña tiene una sola y sale del tenant, así que
        // preguntarlo era ofrecer mirar la de al lado.
        $tenant = $this->operador();
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/estadisticas')
            ->assertStatus(200)
            ->assertJsonPath('meta.tipo', 'alcaldia');
    }

    public function test_el_tipo_invalido_se_rechaza(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/estadisticas?tipo=junta_de_accion_comunal')
            ->assertStatus(422)
            ->assertJsonValidationErrors('tipo');
    }

    public function test_ver_las_estadisticas_exige_view_e14(): void
    {
        $tenant = $this->operador([Permissions::MANAGE_E14]);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/estadisticas?tipo=alcaldia')->assertStatus(403);
    }

    public function test_las_estadisticas_no_ven_las_mesas_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);
        $this->operador(tenant: $ajeno);
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($ajeno, 500, ['voting_place_id' => $lugar->id, 'mesa_votacion' => '5']);
        $this->cargarActa();
        $this->evento($ajeno);

        // El mismo colegio del catálogo global —es el mismo colegio— pero la
        // base, las actas y las mesas son de cada campaña.
        $propio = $this->operador();
        $this->votantes($propio, 10, ['voting_place_id' => $lugar->id, 'mesa_votacion' => '5']);
        $this->cargarActa([
            'votos_urna' => 19,
            'votantes_e11' => 19,
            'suma_declarada' => 19,
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'resultados' => [['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 19]],
        ]);
        $this->evento($propio);

        $this->getJson('/api/v1/e14/estadisticas?tipo=alcaldia')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.base', 10)
            ->assertJsonPath('data.0.votos_candidato', 19)
            ->assertJsonPath('data.0.votos_urna', 19)
            ->assertJsonPath('data.0.votantes_e11', 19)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_sin_ninguna_eleccion_cargada_lo_dice(): void
    {
        $this->operador();

        // El `tipo` de la URL ya no elige nada (0093): la campaña es de
        // alcaldía y todavía no tiene ninguna elección cargada.
        $this->getJson('/api/v1/e14/estadisticas?tipo=gobernacion')
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }
}
