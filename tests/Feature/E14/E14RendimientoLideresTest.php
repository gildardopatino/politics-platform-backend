<?php

namespace Tests\Feature\E14;

use App\Models\ElectoralEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scorecard operativo del líder (Spec 0063 · Parte A).
 *
 * Lo que un líder produce son **reuniones** y, a través de ellas, gente que hace
 * check-in. No posee votantes ni leads, y el voto es secreto en mesas que comparte
 * con otros líderes: por eso aquí no existe —ni puede existir— un «votos del
 * líder». Lo que se prueba es la frontera entre las dos mitades del informe:
 *
 * - **La actividad es cierta**: reuniones, asistentes, check-ins y cuántos de esos
 *   check-ins sabemos dónde votan. Lo que no resuelve se reporta, no se descarta.
 * - **El rendimiento de sus mesas es un proxy declarado**: el déficit de la 0076 en
 *   las mesas donde vota su gente, ponderado por cuánta gente suya hay en cada una,
 *   y compartido con todos los demás líderes que también mueven ahí.
 */
class E14RendimientoLideresTest extends TestCase
{
    private const LUGAR = 'COLEGIO SAN SIMON';

    private const RUTA = '/api/v1/e14/rendimiento-lideres';

    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create();
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

    private function puestoDelCatalogo(array $cambios = []): VotingPlace
    {
        return VotingPlace::create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::LUGAR,
        ], $cambios));
    }

    /** Un acta que cuadra, con los votos que se le quieran dar a mi candidato. */
    private function acta(string $mesa, int $mios, array $cambios = []): void
    {
        $ajenos = 10;
        $total = $mios + $ajenos;

        $this->postJson('/api/v1/e14/actas', array_replace([
            'tipo' => 'alcaldia',
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => $mesa,
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => self::LUGAR,
            'suma_declarada' => $total,
            'votos_urna' => $total,
            'votantes_e11' => $total,
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => $ajenos],
                ['numero' => 2, 'nombre' => 'JOHANA ARANDA', 'votos' => $mios],
            ],
        ], $cambios))->assertSuccessful();
    }

    private function lider(Tenant $tenant, string $nombre): User
    {
        return User::factory()->forTenant($tenant)->create([
            'name' => $nombre,
            'is_team_leader' => true,
        ]);
    }

    private function reunion(Tenant $tenant, User $lider): Meeting
    {
        return Meeting::factory()->forTenant($tenant)->create(['planner_user_id' => $lider->id]);
    }

    private function votante(Tenant $tenant, string $cedula, ?VotingPlace $lugar, ?string $mesa, array $cambios = []): Voter
    {
        return Voter::factory()->forTenant($tenant)->create(array_replace([
            'cedula' => $cedula,
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::LUGAR,
            'mesa_votacion' => $mesa,
            'voting_place_id' => $lugar?->id,
        ], $cambios));
    }

    private function asistente(Meeting $reunion, string $cedula, bool $checkin = true, ?Voter $votante = null): MeetingAttendee
    {
        return MeetingAttendee::create([
            'tenant_id' => $reunion->tenant_id,
            'meeting_id' => $reunion->id,
            'voter_id' => $votante?->id,
            'cedula' => $cedula,
            'nombres' => 'ASISTENTE',
            'apellidos' => $cedula,
            'checked_in' => $checkin,
        ]);
    }

    /**
     * Gente que hace check-in con el líder y vota donde se diga.
     *
     * @return array<int, Voter>
     */
    private function movilizar(Tenant $tenant, Meeting $reunion, int $cuantos, ?VotingPlace $lugar, ?string $mesa, string $prefijo): array
    {
        $votantes = [];

        for ($i = 1; $i <= $cuantos; $i++) {
            $cedula = $prefijo.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
            $votante = $this->votante($tenant, $cedula, $lugar, $mesa);
            $this->asistente($reunion, $cedula, votante: $votante);
            $votantes[] = $votante;
        }

        return $votantes;
    }

    // ------------------------------------------------------- actividad cierta

    public function test_la_actividad_del_lider_es_lo_que_de_verdad_produjo(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $lider = $this->lider($tenant, 'ANA LIDER');

        $reuniones = [
            $this->reunion($tenant, $lider),
            $this->reunion($tenant, $lider),
            $this->reunion($tenant, $lider),
        ];

        // 30 check-ins que sabemos dónde votan…
        $this->movilizar($tenant, $reuniones[0], 15, $lugar, '5', 'A');
        $this->movilizar($tenant, $reuniones[1], 15, $lugar, '5', 'B');

        // …10 que no (nadie les hizo la consulta de Registraduría)…
        for ($i = 1; $i <= 10; $i++) {
            $this->asistente($reuniones[2], 'C'.$i);
        }

        // …y 5 que se inscribieron pero no llegaron.
        for ($i = 1; $i <= 5; $i++) {
            $this->asistente($reuniones[2], 'D'.$i, checkin: false);
        }

        $this->acta('005', 20);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.lider.id', $lider->id)
            ->assertJsonPath('data.0.lider.nombre', 'ANA LIDER')
            ->assertJsonPath('data.0.reuniones', 3)
            ->assertJsonPath('data.0.asistentes', 45)
            ->assertJsonPath('data.0.checkins', 40)
            ->assertJsonPath('data.0.movilizados_identificados', 30)
            // Calidad del dato: de cada 4 personas que movilizó, sabemos dónde
            // votan 3.
            ->assertJsonPath('data.0.identificacion', 75)
            ->assertJsonPath('data.0.puestos_cubiertos', 1)
            ->assertJsonPath('data.0.mesas_cubiertas', 1)
            // Los 10 sin identificar se reportan; no se descartan en silencio.
            ->assertJsonPath('meta.cobertura.checkins_sin_identificar', 10)
            ->assertJsonPath('meta.totales.lideres', 1)
            ->assertJsonPath('meta.totales.reuniones', 3)
            ->assertJsonPath('meta.totales.checkins', 40)
            ->assertJsonPath('meta.totales.movilizados_identificados', 30);
    }

    public function test_quien_repite_reunion_cuenta_una_sola_vez_como_movilizado(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $lider = $this->lider($tenant, 'ANA LIDER');

        $votante = $this->votante($tenant, '111', $lugar, '5');
        $this->asistente($this->reunion($tenant, $lider), '111', votante: $votante);
        $this->asistente($this->reunion($tenant, $lider), '111', votante: $votante);

        $this->acta('005', 1);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            // Dos check-ins de movilización, una sola persona.
            ->assertJsonPath('data.0.checkins', 2)
            ->assertJsonPath('data.0.movilizados_identificados', 1)
            ->assertJsonPath('data.0.identificacion', 50);
    }

    public function test_la_mesa_sale_del_voter_id_y_si_no_de_la_cedula(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $lider = $this->lider($tenant, 'ANA LIDER');
        $reunion = $this->reunion($tenant, $lider);

        // Con `voter_id` (Spec 0022): la persona ya está atada a la asistencia.
        $conVoter = $this->votante($tenant, '111', $lugar, '5');
        $this->asistente($reunion, '111', votante: $conVoter);

        // Sin `voter_id`: la asistencia anterior a la 0022 solo dejó la cédula.
        $this->votante($tenant, '222', $lugar, '7');
        $this->asistente($reunion, '222');

        $this->acta('005', 1);
        $this->acta('007', 1);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.movilizados_identificados', 2)
            ->assertJsonPath('data.0.mesas_cubiertas', 2)
            ->assertJsonPath('data.0.puestos_cubiertos', 1);
    }

    public function test_el_votante_sin_puesto_canonico_se_resuelve_por_nombre(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $lider = $this->lider($tenant, 'ANA LIDER');

        // Captura vieja: tiene el nombre del puesto pero no el id del catálogo.
        $votante = $this->votante($tenant, '111', null, '5', ['puesto_votacion' => 'colegio  san simón']);
        $this->asistente($this->reunion($tenant, $lider), '111', votante: $votante);

        $this->acta('005', 1);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.movilizados_identificados', 1)
            ->assertJsonPath('data.0.mesas_cubiertas', 1)
            ->assertJsonPath('meta.cobertura.checkins_sin_identificar', 0)
            // Resolvió al puesto del catálogo, no a uno nuevo: el resolver de
            // respaldo solo busca.
            ->assertJsonPath('data.0.puestos_cubiertos', 1);

        $this->assertSame(1, VotingPlace::query()->count());
        $this->assertSame($lugar->id, VotingPlace::query()->value('id'));
    }

    public function test_el_checkin_que_no_resuelve_cuenta_como_movilizacion_pero_no_como_identificado(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $lider = $this->lider($tenant, 'ANA LIDER');
        $reunion = $this->reunion($tenant, $lider);

        // Una cédula que no está en la base de la campaña.
        $this->asistente($reunion, '999');

        // Un votante cuyo puesto no existe en el catálogo: no se aproxima.
        $sinPuesto = $this->votante($tenant, '111', null, '5', ['puesto_votacion' => 'ESCUELA QUE NADIE DIO DE ALTA']);
        $this->asistente($reunion, '111', votante: $sinPuesto);

        // Un votante con puesto pero sin mesa: el proxy es por mesa, y una mesa
        // que no se sabe no se inventa.
        $sinMesa = $this->votante($tenant, '222', $lugar, null);
        $this->asistente($reunion, '222', votante: $sinMesa);

        $this->acta('005', 1);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.checkins', 3)
            ->assertJsonPath('data.0.movilizados_identificados', 0)
            ->assertJsonPath('data.0.identificacion', 0)
            ->assertJsonPath('data.0.rendimiento_ponderado', null)
            ->assertJsonPath('data.0.deficit_ponderado', null)
            ->assertJsonPath('data.0.posible_inflado', false)
            ->assertJsonPath('meta.cobertura.checkins_sin_identificar', 3)
            ->assertJsonPath('meta.cobertura.lideres_sin_proxy', 1);
    }

    public function test_un_lider_sin_reuniones_aparece_en_cero_y_no_se_oculta(): void
    {
        $tenant = $this->operador();
        $this->lider($tenant, 'ZOE SIN REUNIONES');
        $this->acta('005', 1);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lider.nombre', 'ZOE SIN REUNIONES')
            ->assertJsonPath('data.0.reuniones', 0)
            ->assertJsonPath('data.0.asistentes', 0)
            ->assertJsonPath('data.0.checkins', 0)
            ->assertJsonPath('data.0.movilizados_identificados', 0)
            // Sin check-ins no hay porcentaje: decir 0 % sería decir que falló.
            ->assertJsonPath('data.0.identificacion', null)
            ->assertJsonPath('data.0.rendimiento_ponderado', null)
            ->assertJsonPath('data.0.posible_inflado', false);
    }

    public function test_el_usuario_que_no_es_lider_no_sale_en_el_ranking(): void
    {
        $tenant = $this->operador();
        $this->lider($tenant, 'ANA LIDER');
        User::factory()->forTenant($tenant)->create(['name' => 'DIGITADOR', 'is_team_leader' => false]);

        $this->acta('005', 1);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.lider.nombre', 'ANA LIDER')
            ->assertJsonCount(1, 'data');
    }

    // --------------------------------------------------- el proxy, no la cosecha

    public function test_el_rendimiento_de_sus_mesas_se_pondera_por_su_presencia(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $lider = $this->lider($tenant, 'ANA LIDER');
        $reunion = $this->reunion($tenant, $lider);

        // Mesa 5: el líder mueve 3 personas; la base de la mesa son 4 (una de
        // otro origen) y mi candidato saca 2 → rendimiento 50 %.
        $this->movilizar($tenant, $reunion, 3, $lugar, '5', 'A');
        $this->votante($tenant, 'Z1', $lugar, '5');

        // Mesa 7: mueve 1; la base son 2 y mi candidato saca 3 → 150 %.
        $this->movilizar($tenant, $reunion, 1, $lugar, '7', 'B');
        $this->votante($tenant, 'Z2', $lugar, '7');

        $this->acta('005', 2);
        $this->acta('007', 3);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.mesas_cubiertas', 2)
            ->assertJsonPath('data.0.mesas_en_deficit', 1)
            ->assertJsonPath('data.0.mesas_en_superavit', 1)
            // (50·3 + 150·1) / 4 = 75 %: la mesa donde tiene más gente pesa más.
            ->assertJsonPath('data.0.rendimiento_ponderado', 75)
            ->assertJsonPath('data.0.deficit_ponderado', 25);
    }

    public function test_el_contrato_no_atribuye_votos_a_ningun_lider(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $lider = $this->lider($tenant, 'ANA LIDER');
        $this->movilizar($tenant, $this->reunion($tenant, $lider), 2, $lugar, '5', 'A');

        $this->acta('005', 1);
        $this->evento($tenant);

        $respuesta = $this->getJson(self::RUTA)->assertStatus(200);

        // El voto es secreto y la mesa se comparte: cualquier «votos del líder»
        // sería inventado.
        $respuesta
            ->assertJsonMissingPath('data.0.votos_lider')
            ->assertJsonMissingPath('data.0.votos')
            ->assertJsonMissingPath('meta.totales.votos_lider')
            ->assertJsonPath('meta.nivel_fijo', 'mesa');

        $this->assertStringContainsString('no se atribuye', $respuesta->json('meta.aviso_proxy'));
    }

    public function test_la_mesa_sin_acta_procesada_no_entra_en_el_proxy(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $lider = $this->lider($tenant, 'ANA LIDER');
        $this->movilizar($tenant, $this->reunion($tenant, $lider), 3, $lugar, '5', 'A');

        // Nada escrutado todavía: la actividad se ve igual, el proxy no.
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.movilizados_identificados', 3)
            ->assertJsonPath('data.0.mesas_cubiertas', 1)
            ->assertJsonPath('data.0.mesas_sin_acta', 1)
            ->assertJsonPath('data.0.mesas_en_deficit', 0)
            // Sin acta no hay con qué comparar: un 0 % diría que rindió mal.
            ->assertJsonPath('data.0.rendimiento_ponderado', null)
            ->assertJsonPath('data.0.posible_inflado', false);
    }

    public function test_posible_inflado_marca_al_que_movilizo_mucho_donde_no_rindio(): void
    {
        // Los umbrales son un parámetro revisable, no un veredicto: la prueba
        // los baja para no tener que fabricar 20 personas por líder.
        config()->set('e14.rendimiento_lideres.umbral_movilizados', 3);
        config()->set('e14.rendimiento_lideres.umbral_deficit_ponderado', 30);

        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();

        // Mucha movilización en una mesa que rindió al 25 %: señal a revisar.
        $sospechoso = $this->lider($tenant, 'ANA LIDER');
        $this->movilizar($tenant, $this->reunion($tenant, $sospechoso), 4, $lugar, '5', 'A');

        // Igual de movilizador, pero sus mesas rindieron: sin señal.
        $limpio = $this->lider($tenant, 'BETO LIDER');
        $this->movilizar($tenant, $this->reunion($tenant, $limpio), 4, $lugar, '7', 'B');

        $this->acta('005', 1);
        $this->acta('007', 4);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.lider.nombre', 'ANA LIDER')
            ->assertJsonPath('data.0.rendimiento_ponderado', 25)
            ->assertJsonPath('data.0.deficit_ponderado', 75)
            ->assertJsonPath('data.0.posible_inflado', true)
            ->assertJsonPath('data.1.lider.nombre', 'BETO LIDER')
            ->assertJsonPath('data.1.rendimiento_ponderado', 100)
            ->assertJsonPath('data.1.posible_inflado', false)
            ->assertJsonPath('meta.umbrales.movilizados_identificados', 3)
            ->assertJsonPath('meta.umbrales.deficit_ponderado', 30);
    }

    public function test_poca_movilizacion_no_se_marca_aunque_la_mesa_rinda_mal(): void
    {
        // El umbral de volumen existe justo para esto: un líder con dos personas
        // en una mesa mala no tiene una base inflada, tiene dos personas.
        config()->set('e14.rendimiento_lideres.umbral_movilizados', 20);

        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $lider = $this->lider($tenant, 'ANA LIDER');
        $this->movilizar($tenant, $this->reunion($tenant, $lider), 2, $lugar, '5', 'A');

        $this->acta('005', 0);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('data.0.rendimiento_ponderado', 0)
            ->assertJsonPath('data.0.deficit_ponderado', 100)
            ->assertJsonPath('data.0.posible_inflado', false);
    }

    public function test_los_umbrales_por_defecto_son_los_documentados(): void
    {
        $this->assertSame(20, config('e14.rendimiento_lideres.umbral_movilizados'));
        $this->assertSame(30.0, config('e14.rendimiento_lideres.umbral_deficit_ponderado'));
    }

    // -------------------------------------------------------------- el ranking

    public function test_por_defecto_arriba_va_el_mayor_deficit_ponderado(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();

        $bueno = $this->lider($tenant, 'ANA LIDER');       // mesa 7 al 100 %
        $malo = $this->lider($tenant, 'BETO LIDER');       // mesa 5 al 25 %
        $sinDatos = $this->lider($tenant, 'CARO LIDER');   // sin movilización

        $this->movilizar($tenant, $this->reunion($tenant, $bueno), 4, $lugar, '7', 'A');
        $this->movilizar($tenant, $this->reunion($tenant, $malo), 4, $lugar, '5', 'B');
        $this->reunion($tenant, $sinDatos);

        $this->acta('005', 1);
        $this->acta('007', 4);
        $this->evento($tenant);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonPath('meta.orden', 'deficit')
            // Lo accionable arriba; quien no tiene proxy no se cuela entre medias.
            ->assertJsonPath('data.0.lider.id', $malo->id)
            ->assertJsonPath('data.1.lider.id', $bueno->id)
            ->assertJsonPath('data.2.lider.id', $sinDatos->id);
    }

    public function test_se_puede_ordenar_por_actividad(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();

        $poco = $this->lider($tenant, 'ANA LIDER');
        $mucho = $this->lider($tenant, 'BETO LIDER');

        $this->movilizar($tenant, $this->reunion($tenant, $poco), 1, $lugar, '5', 'A');
        $this->movilizar($tenant, $this->reunion($tenant, $mucho), 4, $lugar, '7', 'B');

        $this->acta('005', 0);
        $this->acta('007', 4);
        $this->evento($tenant);

        $this->getJson(self::RUTA.'?order=actividad')
            ->assertStatus(200)
            ->assertJsonPath('meta.orden', 'actividad')
            ->assertJsonPath('data.0.lider.id', $mucho->id)
            ->assertJsonPath('data.1.lider.id', $poco->id);
    }

    public function test_un_orden_que_no_existe_se_rechaza(): void
    {
        $tenant = $this->operador();
        $this->acta('005', 1);
        $this->evento($tenant);

        $this->getJson(self::RUTA.'?order=votos')
            ->assertStatus(422)
            ->assertJsonValidationErrors('order');
    }

    // ------------------------------------------------------- errores y permisos

    public function test_sin_candidato_propio_pide_configurarlo(): void
    {
        $tenant = $this->operador();
        $this->acta('005', 1);
        $this->evento($tenant, numero: null);

        $this->getJson(self::RUTA)
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }

    public function test_ver_el_rendimiento_de_lideres_exige_view_e14(): void
    {
        $this->operador([Permissions::MANAGE_E14]);

        $this->getJson(self::RUTA)->assertStatus(403);
    }

    public function test_no_ve_los_lideres_ni_la_movilizacion_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create();
        $lugar = $this->puestoDelCatalogo();

        // La otra campaña: su líder, su gente y sus actas.
        $this->operador(tenant: $ajeno);
        $liderAjeno = $this->lider($ajeno, 'LIDER DE LA OTRA CAMPANA');
        $this->movilizar($ajeno, $this->reunion($ajeno, $liderAjeno), 3, $lugar, '5', 'X');
        $this->acta('005', 3);
        $this->evento($ajeno);

        // La mía: un líder que no movilizó a nadie.
        $mio = $this->operador();
        $lider = $this->lider($mio, 'ANA LIDER');
        $this->acta('005', 1);
        $this->evento($mio);

        $this->getJson(self::RUTA)
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.lider.id', $lider->id)
            ->assertJsonPath('data.0.movilizados_identificados', 0)
            ->assertJsonPath('meta.totales.checkins', 0)
            ->assertJsonMissingPath('data.1');
    }

    public function test_no_se_puede_pedir_la_eleccion_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->create();
        $this->operador(tenant: $ajeno);
        $this->acta('005', 3);
        $eventoAjeno = $this->evento($ajeno);

        $mio = $this->operador();
        $this->acta('005', 1);
        $this->evento($mio);

        $this->getJson(self::RUTA.'?event='.$eventoAjeno->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }

    // ---------------------------------------------------------------- sin N+1

    public function test_el_numero_de_consultas_no_crece_con_los_lideres(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();

        $primero = $this->lider($tenant, 'LIDER 1');
        $this->movilizar($tenant, $this->reunion($tenant, $primero), 2, $lugar, '5', 'A');

        $this->acta('005', 1);
        $this->acta('007', 1);
        $this->evento($tenant);

        $conUno = $this->consultas();

        // Cuatro líderes más, cada uno con su reunión, su gente y su mesa.
        foreach (range(2, 5) as $n) {
            $lider = $this->lider($tenant, 'LIDER '.$n);
            $this->movilizar($tenant, $this->reunion($tenant, $lider), 2, $lugar, $n % 2 === 0 ? '5' : '7', 'L'.$n);
        }

        $conCinco = $this->consultas();

        $this->assertSame(
            $conUno,
            $conCinco,
            'El scorecard agrega con mapas en memoria: cinco líderes tienen que costar lo mismo que uno.'
        );
    }

    private function consultas(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->getJson(self::RUTA)->assertStatus(200);

        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    }
}
