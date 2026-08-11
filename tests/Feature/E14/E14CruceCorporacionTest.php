<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * El cruce de una campaña a corporación (Spec 0083).
 *
 * El circuito ya estaba entero salvo este eslabón: se lee el acta de concejo
 * (0067), se configura el candidato como `(lista, preferente)` (0082)… y el
 * cruce seguía buscándolo en `e14_resultados` con un número suelto, donde para
 * corporación no hay nada. Un tenant de concejo bien configurado veía el panel
 * en ceros.
 *
 * Lo que cambia es **solo de dónde salen los votos**. La base identificada, el
 * déficit, el excedente y la cobertura de la 0076 son los mismos de siempre:
 * este archivo lo comprueba con los mismos números del uninominal.
 */
class E14CruceCorporacionTest extends TestCase
{
    private const LUGAR = 'UNIVERSIDAD COOPERATIVA';

    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->corporacion()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    /** La elección de concejo con el candidato ya fijado en `(lista, preferente)`. */
    private function evento(Tenant $tenant, ?int $lista = 11, ?int $preferente = 5): ElectoralEvent
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['tenant_id' => $tenant->id, 'tipo' => E14Acta::TIPO_CONCEJO, 'nombre' => 'Concejo'],
            ['fecha' => '2027-10-31'],
        );

        $evento->update([
            'candidato_propio_lista_numero' => $lista,
            'candidato_propio_numero' => $preferente,
            'candidato_propio_nombre' => 'ANA RUIZ',
            'candidato_propio_agrupacion' => 'PARTIDO CENTRO DEMOCRÁTICO',
        ]);

        return $evento;
    }

    /**
     * Acta de concejo que cuadra.
     *
     * Dos agrupaciones con el **mismo** número de preferencia, el 5: en la mía
     * (lista 11) saca 30, en la otra (lista 1) saca 99. Son dos personas.
     * 30 + 2 (otros preferentes de mi lista) + 4 solo lista = 36 de la 11;
     * 99 + 1 + 5 = 105 de la 1; + 4 + 4 + 4 de controles = 153 en la urna.
     */
    private function cargarActa(array $cambios = []): void
    {
        $this->postJson('/api/v1/e14/actas', array_replace([
            'tipo' => E14Acta::TIPO_CONCEJO,
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '005',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => self::LUGAR,
            'listas' => [
                [
                    'lista_numero' => 11,
                    'lista_nombre' => 'PARTIDO CENTRO DEMOCRÁTICO',
                    'votos_solo_lista' => 4,
                    'total_agrupacion' => 36,
                    'con_voto_preferente' => true,
                    'preferentes' => [
                        ['numero' => 5, 'votos' => 30],
                        ['numero' => 9, 'votos' => 2],
                    ],
                ],
                [
                    'lista_numero' => 1,
                    'lista_nombre' => 'PARTIDO LIBERAL COLOMBIANO',
                    'votos_solo_lista' => 5,
                    'total_agrupacion' => 105,
                    'con_voto_preferente' => true,
                    'preferentes' => [
                        ['numero' => 5, 'votos' => 99],
                        ['numero' => 7, 'votos' => 1],
                    ],
                ],
            ],
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            // Sin `suma_declarada`: esa casilla no existe en el papel de corporación.
            'votos_urna' => 153,
            'votantes_e11' => 153,
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

    private function puestoDelCatalogo(): VotingPlace
    {
        return VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::LUGAR,
        ]);
    }

    // ---------------------------------------------------------- el cálculo

    public function test_cuenta_los_votos_de_mi_lista_y_mi_preferente(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $this->evento($tenant, lista: 11, preferente: 5);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.voting_place_id', $lugar->id)
            ->assertJsonPath('data.0.base', 50)
            // 30, no 129: el 5 del PARTIDO LIBERAL es otra persona.
            ->assertJsonPath('data.0.votos_candidato', 30)
            // Y el déficit de la 0076 sale igual que en uninominal.
            ->assertJsonPath('data.0.rendimiento', 60)
            ->assertJsonPath('data.0.diferencia', -20)
            ->assertJsonPath('data.0.deficit', 20)
            ->assertJsonPath('data.0.excedente', 0)
            ->assertJsonPath('data.0.tiene_acta', true)
            ->assertJsonPath('meta.totales.deficit_total', 20)
            ->assertJsonPath('meta.totales.puestos_con_deficit', 1);
    }

    public function test_el_mismo_preferente_de_otra_lista_es_otro_candidato(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        // Misma persona-número, otra lista: el cruce tiene que dar los 99.
        $this->evento($tenant, lista: 1, preferente: 5);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.votos_candidato', 99)
            // Y con ellos, el excedente de la 0076 sin tocar una línea.
            ->assertJsonPath('data.0.deficit', 0)
            ->assertJsonPath('data.0.excedente', 49);
    }

    public function test_los_votos_solo_por_la_lista_no_son_del_candidato(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 10, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        // El 9 de mi lista saca 2. Si se le sumaran los 4 «solo lista» de la
        // agrupación darían 6, y esos votos no son de nadie en particular.
        $this->evento($tenant, lista: 11, preferente: 9);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.votos_candidato', 2);
    }

    public function test_un_preferente_sin_votos_en_el_acta_es_cero_con_acta(): void
    {
        // Cero real, no dato faltante: la cobertura dice que el acta sí llegó.
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 30, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $this->evento($tenant, lista: 11, preferente: 19);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.votos_candidato', 0)
            ->assertJsonPath('data.0.tiene_acta', true)
            ->assertJsonPath('data.0.deficit', 30)
            ->assertJsonPath('meta.cobertura.puestos_sin_acta', 0);
    }

    public function test_a_nivel_de_mesa_tambien(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/cruce?nivel=mesa')
            ->assertStatus(200)
            // `005` en el acta y `5` en la base son la misma mesa.
            ->assertJsonPath('data.0.mesa', 5)
            ->assertJsonPath('data.0.base', 50)
            ->assertJsonPath('data.0.votos_candidato', 30);
    }

    public function test_no_ve_los_preferentes_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->corporacion()->create();
        $this->operador(tenant: $ajeno);
        $lugar = $this->puestoDelCatalogo();
        // La otra campaña carga su acta, con mi misma lista y mi mismo preferente.
        $this->cargarActa();
        $this->evento($ajeno);

        $tenant = $this->operador();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonPath('data.0.base', 50)
            ->assertJsonPath('data.0.votos_candidato', 0)
            ->assertJsonPath('data.0.tiene_acta', false);
    }

    // ------------------------------------------- el rendimiento hereda (RF-4)

    public function test_el_rendimiento_de_lideres_usa_los_votos_de_lista_y_preferente(): void
    {
        // `RendimientoLideresService` no sabe nada de listas: recibe los votos
        // por mesa ya ramificados y hace exactamente lo que hacía (RF-4).
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->cargarActa();
        $this->evento($tenant, lista: 11, preferente: 5);

        $lider = User::factory()->forTenant($tenant)->create([
            'name' => 'CARLOS MEJIA',
            'is_team_leader' => true,
        ]);
        $reunion = Meeting::factory()->forTenant($tenant)->create(['planner_user_id' => $lider->id]);

        // 20 personas suyas que votan en la mesa 5 del puesto: esa es su base.
        for ($i = 1; $i <= 20; $i++) {
            $cedula = 'M'.str_pad((string) $i, 4, '0', STR_PAD_LEFT);

            $votante = Voter::factory()->forTenant($tenant)->create([
                'cedula' => $cedula,
                'departamento_votacion' => 'TOLIMA',
                'municipio_votacion' => 'IBAGUE',
                'puesto_votacion' => self::LUGAR,
                'mesa_votacion' => '5',
                'voting_place_id' => $lugar->id,
            ]);

            MeetingAttendee::create([
                'tenant_id' => $tenant->id,
                'meeting_id' => $reunion->id,
                'voter_id' => $votante->id,
                'cedula' => $cedula,
                'nombres' => 'ASISTENTE',
                'apellidos' => $cedula,
                'checked_in' => true,
            ]);
        }

        $this->getJson('/api/v1/e14/rendimiento-lideres')
            ->assertStatus(200)
            ->assertJsonPath('data.0.lider.nombre', 'CARLOS MEJIA')
            ->assertJsonPath('data.0.movilizados_identificados', 20)
            // 30 votos sobre una base de 20. Si se hubiera contado el 5 «a
            // secas» —30 + 99— saldría 645 % y el líder tendría un rendimiento
            // inventado con los votos de otra lista.
            ->assertJsonPath('data.0.rendimiento_ponderado', 150)
            ->assertJsonPath('data.0.mesas_en_deficit', 0);
    }
}
