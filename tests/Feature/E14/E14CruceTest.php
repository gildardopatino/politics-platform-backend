<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Cruce: déficit sobre la base identificada (Specs 0062 y 0076).
 *
 * «Tengo 50 personas identificadas en este puesto y mi candidato saco 30 votos»:
 * eso es lo que se prueba aquí, y con él las tres reglas que lo gobiernan — la base
 * son los `voters` (y opcionalmente los `leads`) del tenant, el real es la fila del
 * E-14 de mi candidato en actas que cuadran, y el match es **exacto** por puesto
 * canónico + mesa normalizada.
 *
 * La 0076 corrigió la semántica: la base **no** es el censo del puesto, es un
 * subconjunto del electorado, así que `votos > base` es lo normal —**excedente**,
 * dato neutro— y la señal accionable es la contraria, el **déficit**. El flag
 * `anomalia` de la 0062 desapareció con su mensaje.
 *
 * Lo que no casa no se aproxima: se cuenta en la cobertura.
 */
class E14CruceTest extends TestCase
{
    private const LUGAR = 'COLEGIO SAN SIMON';

    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    /** La elección con su candidato propio ya fijado. */
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
            'votantes_e11' => 111,
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

    // ---------------------------------------------------------- el cálculo

    public function test_muestra_la_base_los_votos_el_rendimiento_y_la_diferencia(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.voting_place_id', $lugar->id)
            ->assertJsonPath('data.0.puesto', self::LUGAR)
            ->assertJsonPath('data.0.municipio', 'IBAGUE')
            ->assertJsonPath('data.0.base', 50)
            ->assertJsonPath('data.0.votos_candidato', 30)
            ->assertJsonPath('data.0.rendimiento', 60)
            ->assertJsonPath('data.0.diferencia', -20)
            // `anomalia` ya no existe (Spec 0076): la base identificada no es el
            // censo del puesto, así que sacar más votos que ella no es un error.
            ->assertJsonMissingPath('data.0.anomalia')
            ->assertJsonPath('data.0.tiene_acta', true)
            // Por puesto no hay columna de mesa: sería una columna siempre vacía.
            ->assertJsonMissingPath('data.0.mesa')
            ->assertJsonPath('meta.candidato.numero', 2)
            ->assertJsonPath('meta.totales.base', 50)
            ->assertJsonPath('meta.totales.votos_candidato', 30)
            ->assertJsonPath('meta.totales.rendimiento', 60);
    }

    public function test_por_mesa_el_005_del_acta_casa_con_el_5_del_votante(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 40, ['voting_place_id' => $lugar->id, 'mesa_votacion' => '5']);
        // Otra mesa del mismo puesto, para que el nivel importe.
        $this->votantes($tenant, 10, ['voting_place_id' => $lugar->id, 'mesa_votacion' => '6']);
        $this->cargarActa(['mesa' => '005']);
        $this->evento($tenant);

        $respuesta = $this->getJson('/api/v1/e14/cruce?nivel=mesa')->assertStatus(200);

        $respuesta->assertJsonPath('data.0.mesa', 5)
            ->assertJsonPath('data.0.base', 40)
            ->assertJsonPath('data.0.votos_candidato', 30)
            ->assertJsonPath('data.1.mesa', 6)
            ->assertJsonPath('data.1.base', 10)
            ->assertJsonPath('data.1.votos_candidato', 0)
            ->assertJsonPath('data.1.tiene_acta', false)
            ->assertJsonPath('meta.cobertura.puestos_sin_acta', 1);
    }

    public function test_solo_cuentan_las_actas_que_cuadran(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        // Declara 120 y las casillas suman 111: no cuadra, así que no se usa
        // para juzgar el rendimiento de nadie.
        $this->cargarActa(['suma_declarada' => 120, 'votos_urna' => 120]);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.base', 50)
            ->assertJsonPath('data.0.votos_candidato', 0)
            ->assertJsonPath('data.0.tiene_acta', false)
            ->assertJsonPath('meta.cobertura.puestos_sin_acta', 1);
    }

    public function test_un_puesto_con_acta_y_sin_base_sale_en_la_cobertura(): void
    {
        $tenant = $this->operador();
        $this->cargarActa();
        $this->evento($tenant);

        // Ahí votó gente que esta campaña no tiene identificada: es la mitad más
        // interesante de la cobertura, así que la fila existe.
        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.base', 0)
            ->assertJsonPath('data.0.votos_candidato', 30)
            ->assertJsonPath('data.0.rendimiento', null)
            ->assertJsonMissingPath('data.0.anomalia')
            ->assertJsonPath('meta.cobertura.puestos_sin_base', 1);
    }

    public function test_mas_votos_que_la_base_ya_no_es_una_anomalia(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 10, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $this->evento($tenant);

        // La base identificada es un **subconjunto** del electorado: el candidato
        // recibe votos de mucha gente que la campaña no tiene en el sistema. Por
        // eso `votos > base` es lo normal y no informa de nada malo.
        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.diferencia', 20)
            ->assertJsonMissingPath('data.0.anomalia')
            ->assertJsonMissingPath('meta.totales.anomalias');
    }

    public function test_cambiar_el_candidato_propio_recalcula_el_cruce(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 100, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $evento = $this->evento($tenant);

        $this->getJson('/api/v1/e14/cruce')->assertJsonPath('data.0.votos_candidato', 30);

        $this->putJson("/api/v1/e14/eventos/{$evento->id}/candidato-propio", ['numero' => 1])
            ->assertStatus(200);

        // Nada guardado que recalcular: el cruce se calcula al preguntarlo.
        $this->getJson('/api/v1/e14/cruce')
            ->assertJsonPath('data.0.votos_candidato', 50)
            ->assertJsonPath('data.0.rendimiento', 50)
            ->assertJsonPath('meta.candidato.numero', 1);
    }

    public function test_sin_candidato_propio_el_cruce_pide_configurarlo(): void
    {
        $tenant = $this->operador();
        $this->cargarActa();
        $this->evento($tenant, numero: null);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }

    public function test_sin_elecciones_cargadas_lo_dice(): void
    {
        $this->operador();

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }

    // ------------------------------------------------- el lado del votante

    public function test_el_votante_sin_puesto_se_resuelve_por_nombre(): void
    {
        $tenant = $this->operador();
        $this->cargarActa();
        $this->evento($tenant);

        // Captura vieja (o webhook): tiene los nombres pero no el puesto. El
        // acta ya dio de alta el renglón del catálogo, así que casan.
        $this->votantes($tenant, 50, [
            'voting_place_id' => null,
            'municipio_votacion' => 'Ibagué',
            'puesto_votacion' => 'colegio san simón',
        ]);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.base', 50)
            ->assertJsonPath('data.0.votos_candidato', 30)
            ->assertJsonPath('meta.cobertura.base_sin_conciliar', 0);
    }

    public function test_el_votante_cuyo_puesto_no_resuelve_queda_por_conciliar(): void
    {
        $tenant = $this->operador();
        $this->cargarActa();
        $this->evento($tenant);

        // «COL.» no es «COLEGIO»: unirlos sería adivinar. Ni entra en la fila ni
        // desaparece — se cuenta para que alguien lo fusione.
        $this->votantes($tenant, 50, [
            'voting_place_id' => null,
            'puesto_votacion' => 'COL. SAN SIMON',
        ]);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.base', 0)
            ->assertJsonPath('meta.cobertura.base_sin_conciliar', 50)
            ->assertJsonPath('meta.cobertura.nombres_sin_conciliar', 1);
    }

    public function test_los_leads_se_cuentan_solo_si_se_piden(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 30, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $this->evento($tenant);

        Lead::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'cedula' => '1110001111',
            'nombre1' => 'ANA',
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::LUGAR,
            'mesa_votacion' => '5',
        ]);

        $this->getJson('/api/v1/e14/cruce')
            ->assertJsonPath('data.0.base', 30)
            ->assertJsonPath('meta.incluir', 'voters');

        $this->getJson('/api/v1/e14/cruce?incluir=ambos')
            ->assertJsonPath('data.0.base', 31)
            ->assertJsonPath('meta.incluir', 'ambos');

        $this->getJson('/api/v1/e14/cruce?incluir=leads')
            ->assertJsonPath('data.0.base', 1);
    }

    // ------------------------------------------------- cobertura y filtros

    public function test_un_acta_sin_lugar_no_cuelga_de_ningun_puesto(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 20, ['voting_place_id' => $lugar->id]);
        $this->cargarActa(['lugar' => null, 'mesa' => '007']);
        $this->evento($tenant);

        // Sus votos no entran en ninguna fila, y se dice cuántas actas son.
        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.votos_candidato', 0)
            ->assertJsonPath('meta.cobertura.actas_sin_conciliar', 1);
    }

    public function test_filtra_por_municipio_y_por_puesto(): void
    {
        $tenant = $this->operador();
        $ibague = $this->puestoDelCatalogo();
        $armero = $this->puestoDelCatalogo([
            'municipio_votacion' => 'ARMERO',
            'puesto_votacion' => 'ESCUELA LA PALMA',
        ]);

        $this->votantes($tenant, 20, ['voting_place_id' => $ibague->id]);
        $this->votantes($tenant, 30, [
            'voting_place_id' => $armero->id,
            'municipio_votacion' => 'ARMERO',
            'puesto_votacion' => 'ESCUELA LA PALMA',
        ]);
        $this->cargarActa();
        $this->cargarActa([
            'mesa' => '006',
            'municipio' => 'ARMERO',
            'lugar' => 'ESCUELA LA PALMA',
        ]);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/cruce')->assertJsonCount(2, 'data');

        $this->getJson('/api/v1/e14/cruce?municipio=armero')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.municipio', 'ARMERO')
            ->assertJsonPath('meta.totales.base', 30);

        // Por id, como cuando se elige de la lista…
        $this->getJson("/api/v1/e14/cruce?voting_place={$ibague->id}")
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.voting_place_id', $ibague->id);

        // …y por texto, como cuando se escribe en la casilla.
        $this->getJson('/api/v1/e14/cruce?voting_place=palma')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.puesto', 'ESCUELA LA PALMA');
    }

    public function test_el_nivel_invalido_se_rechaza(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/cruce?nivel=barrio')
            ->assertStatus(422)
            ->assertJsonValidationErrors('nivel');
    }

    // ---------------------------------------------- permisos y aislamiento

    public function test_ver_el_cruce_exige_view_e14(): void
    {
        $tenant = $this->operador([Permissions::MANAGE_E14]);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/cruce')->assertStatus(403);
    }

    public function test_el_cruce_no_ve_los_datos_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create();
        $this->operador(tenant: $ajeno);
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($ajeno, 500, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $this->evento($ajeno);

        // La otra campaña usa el mismo puesto del catálogo global —eso es
        // correcto, es el mismo colegio— pero sus registrados y sus actas son
        // suyos.
        $propio = $this->operador();
        $this->votantes($propio, 10, ['voting_place_id' => $lugar->id]);
        $this->cargarActa(['resultados' => [['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => 7]], 'suma_declarada' => 19, 'votos_urna' => 19, 'votantes_e11' => 19]);
        $this->evento($propio);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.base', 10)
            ->assertJsonPath('data.0.votos_candidato', 7);
    }

    public function test_no_se_puede_cruzar_la_eleccion_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create();
        $eventoAjeno = ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $ajeno->id,
            'tipo' => 'alcaldia',
            'nombre' => 'Alcaldía',
            'candidato_propio_numero' => 2,
        ]);

        $propio = $this->operador();
        $this->evento($propio);

        $this->getJson("/api/v1/e14/cruce?event={$eventoAjeno->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }

    public function test_las_actas_de_la_cola_no_entran_en_el_cruce(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 20, ['voting_place_id' => $lugar->id]);
        $evento = $this->evento($tenant);

        // Un acta que nadie ha leído todavía no tiene votos que cruzar.
        E14Acta::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => 'alcaldia',
            'estado' => E14Acta::ESTADO_PENDIENTE,
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => self::LUGAR,
            'voting_place_id' => $lugar->id,
        ]);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.votos_candidato', 0)
            ->assertJsonPath('data.0.tiene_acta', false);
    }
}
