<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\E14MetaPuesto;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proyección: meta vs base identificada vs votos reales (Spec 0064 · RF-2).
 *
 * El tablero de «¿voy ganando?». Contrasta tres números que ya existen y **no**
 * los recalcula: la meta que se fijó a mano (0064 · RF-1), la base identificada
 * de la 0076 y los votos reales del cruce (0062/0083). La proyección orquesta.
 *
 * La regla que gobierna el semáforo es la honestidad: si hay actas se juzga sobre
 * los **votos reales**; si no, sobre la base, **rotulando cuál de las dos** se
 * está mirando. La base no es voto asegurado —eso es justo lo que la 0076 vino a
 * dejar claro—, así que un semáforo verde sobre base y uno sobre votos no
 * significan lo mismo y el contrato no los puede confundir.
 */
class E14ProyeccionTest extends TestCase
{
    private const LUGAR = 'COLEGIO SAN SIMON';

    private const OTRO_LUGAR = 'INSTITUCION EDUCATIVA SAN JOSE';

    private function operador(
        array $permisos = [Permissions::VIEW_E14, Permissions::MANAGE_E14],
        ?Tenant $tenant = null
    ): Tenant {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser($permisos, $tenant);

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    /** La elección de alcaldía con su candidato ya fijado. */
    private function evento(Tenant $tenant, ?int $numero = 2, ?int $metaGlobal = null): ElectoralEvent
    {
        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->firstOrCreate(
            ['tenant_id' => $tenant->id, 'tipo' => 'alcaldia', 'nombre' => 'Alcaldía'],
            ['fecha' => '2027-10-31'],
        );

        $evento->update([
            'candidato_propio_numero' => $numero,
            'candidato_propio_nombre' => $numero === null ? null : 'JOHANA ARANDA',
            'meta_votos' => $metaGlobal,
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

    private function metaDelPuesto(Tenant $tenant, ElectoralEvent $evento, VotingPlace $lugar, int $meta): void
    {
        E14MetaPuesto::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'voting_place_id' => $lugar->id,
            'meta_votos' => $meta,
        ]);
    }

    // ------------------------------------------------------- el contraste

    public function test_contrasta_meta_base_y_votos_reales_por_puesto(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $evento = $this->evento($tenant);
        $this->metaDelPuesto($tenant, $evento, $lugar, 300);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            ->assertJsonPath('data.0.puesto', self::LUGAR)
            ->assertJsonPath('data.0.meta', 300)
            ->assertJsonPath('data.0.identificados', 50)
            ->assertJsonPath('data.0.votos_reales', 30)
            ->assertJsonPath('data.0.avance_base', 16.67)
            ->assertJsonPath('data.0.avance_real', 10)
            // Lo que falta se mide contra lo cierto —los votos— cuando lo hay.
            ->assertJsonPath('data.0.faltante', 270)
            ->assertJsonPath('data.0.semaforo', 'rojo')
            ->assertJsonPath('data.0.base_del_semaforo', 'real');
    }

    public function test_sin_actas_los_votos_van_en_nulo_y_el_avance_se_mide_sobre_la_base(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 80, ['voting_place_id' => $lugar->id]);
        $evento = $this->evento($tenant);
        $this->metaDelPuesto($tenant, $evento, $lugar, 100);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            // `null` y no `0`: «todavía no hay acta» no es «sacó cero votos».
            ->assertJsonPath('data.0.votos_reales', null)
            ->assertJsonPath('data.0.tiene_actas', false)
            ->assertJsonPath('data.0.avance_real', null)
            ->assertJsonPath('data.0.avance_base', 80)
            ->assertJsonPath('data.0.faltante', 20)
            ->assertJsonPath('data.0.semaforo', 'ambar')
            // Rotulado: un 80 % sobre base no es un 80 % sobre votos.
            ->assertJsonPath('data.0.base_del_semaforo', 'base');
    }

    public function test_un_puesto_con_acta_donde_saco_cero_no_es_un_puesto_sin_acta(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        // El acta existe y cuadra, pero mi candidato (el 2) no aparece en ella.
        $this->cargarActa([
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JORGE BOLIVAR TORRES', 'votos' => 99],
            ],
            'suma_declarada' => 111,
            'votos_urna' => 111,
            'votantes_e11' => 111,
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
        ]);
        $evento = $this->evento($tenant);
        $this->metaDelPuesto($tenant, $evento, $lugar, 300);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            ->assertJsonPath('data.0.votos_reales', 0)
            ->assertJsonPath('data.0.tiene_actas', true)
            ->assertJsonPath('data.0.avance_real', 0)
            ->assertJsonPath('data.0.base_del_semaforo', 'real');
    }

    // ---------------------------------------------------------- semáforo

    public function test_el_semaforo_enciende_por_los_umbrales_configurados(): void
    {
        config(['e14.proyeccion.umbral_verde' => 90, 'e14.proyeccion.umbral_ambar' => 70]);

        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 95, ['voting_place_id' => $lugar->id]);
        $evento = $this->evento($tenant);
        $this->metaDelPuesto($tenant, $evento, $lugar, 100);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            ->assertJsonPath('data.0.semaforo', 'verde')
            ->assertJsonPath('meta.umbrales.verde', 90)
            ->assertJsonPath('meta.umbrales.ambar', 70);
    }

    public function test_mover_los_umbrales_mueve_el_semaforo(): void
    {
        config(['e14.proyeccion.umbral_verde' => 99, 'e14.proyeccion.umbral_ambar' => 96]);

        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 95, ['voting_place_id' => $lugar->id]);
        $evento = $this->evento($tenant);
        $this->metaDelPuesto($tenant, $evento, $lugar, 100);

        // El mismo 95 % que antes era verde ahora se queda en rojo.
        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            ->assertJsonPath('data.0.semaforo', 'rojo');
    }

    public function test_sin_meta_no_hay_avance_ni_semaforo_que_encender(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            ->assertJsonPath('data.0.meta', null)
            // Dividir por cero no da rojo, da nada. Un rojo aquí acusaría a la
            // campaña de no llegar a una meta que nadie fijó.
            ->assertJsonPath('data.0.avance_base', null)
            ->assertJsonPath('data.0.avance_real', null)
            ->assertJsonPath('data.0.faltante', null)
            ->assertJsonPath('data.0.semaforo', 'sin_meta')
            ->assertJsonPath('data.0.base_del_semaforo', null);
    }

    public function test_una_meta_en_cero_se_trata_como_meta_sin_fijar(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $evento = $this->evento($tenant);
        $this->metaDelPuesto($tenant, $evento, $lugar, 0);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            ->assertJsonPath('data.0.avance_base', null)
            ->assertJsonPath('data.0.semaforo', 'sin_meta');
    }

    public function test_identificar_mas_que_la_meta_no_es_un_error(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 150, ['voting_place_id' => $lugar->id]);
        $evento = $this->evento($tenant);
        $this->metaDelPuesto($tenant, $evento, $lugar, 100);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            ->assertJsonPath('data.0.avance_base', 150)
            ->assertJsonPath('data.0.faltante', 0)
            ->assertJsonPath('data.0.semaforo', 'verde')
            // …pero sigue sin ser voto, y el contrato lo dice.
            ->assertJsonPath('data.0.base_del_semaforo', 'base');
    }

    public function test_el_contrato_avisa_de_que_la_base_no_es_voto_asegurado(): void
    {
        $tenant = $this->operador();
        $this->puestoDelCatalogo();
        $this->evento($tenant);

        $respuesta = $this->getJson('/api/v1/e14/proyeccion')->assertOk()->json();

        $this->assertStringContainsString('no es voto asegurado', $respuesta['meta']['aviso_base']);
    }

    // ------------------------------------------------------------ niveles

    public function test_el_nivel_municipio_agrega_los_puestos_de_cada_uno(): void
    {
        $tenant = $this->operador();
        $ibague = $this->puestoDelCatalogo();
        $otroDeIbague = $this->puestoDelCatalogo(['puesto_votacion' => self::OTRO_LUGAR]);
        $espinal = $this->puestoDelCatalogo([
            'municipio_votacion' => 'ESPINAL',
            'puesto_votacion' => 'COLEGIO SAN JUAN',
        ]);

        $this->votantes($tenant, 50, ['voting_place_id' => $ibague->id]);
        $this->votantes($tenant, 30, [
            'voting_place_id' => $otroDeIbague->id,
            'puesto_votacion' => self::OTRO_LUGAR,
        ]);
        $this->votantes($tenant, 10, [
            'voting_place_id' => $espinal->id,
            'municipio_votacion' => 'ESPINAL',
            'puesto_votacion' => 'COLEGIO SAN JUAN',
        ]);

        $evento = $this->evento($tenant);
        $this->metaDelPuesto($tenant, $evento, $ibague, 300);
        $this->metaDelPuesto($tenant, $evento, $otroDeIbague, 200);
        $this->metaDelPuesto($tenant, $evento, $espinal, 100);

        $filas = collect($this->getJson('/api/v1/e14/proyeccion?nivel=municipio')->assertOk()->json('data'));

        $this->assertCount(2, $filas);

        $conIbague = $filas->firstWhere('municipio', 'IBAGUE');
        // La meta del municipio **se agrega de sus puestos**: no hay tabla de
        // municipio que pueda contradecir a la de sus puestos.
        $this->assertSame(500, $conIbague['meta']);
        $this->assertSame(80, $conIbague['identificados']);
        $this->assertSame(2, $conIbague['puestos']);
        $this->assertArrayNotHasKey('voting_place_id', $conIbague);

        $this->assertSame(100, $filas->firstWhere('municipio', 'ESPINAL')['meta']);
    }

    public function test_el_nivel_global_devuelve_una_fila_con_la_meta_global(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $this->cargarActa();
        $evento = $this->evento($tenant, 2, 1000);
        $this->metaDelPuesto($tenant, $evento, $lugar, 300);

        $this->getJson('/api/v1/e14/proyeccion?nivel=global')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            // La global es la que fijó la campaña, **no** la suma de los puestos:
            // repartir la meta entre puestos es un ejercicio aparte de decidir a
            // cuánto se aspira.
            ->assertJsonPath('data.0.meta', 1000)
            ->assertJsonPath('data.0.meta_origen', 'global')
            ->assertJsonPath('data.0.identificados', 50)
            ->assertJsonPath('data.0.votos_reales', 30)
            ->assertJsonPath('data.0.faltante', 970)
            ->assertJsonPath('data.0.puestos', 1);
    }

    public function test_sin_meta_global_el_ambito_cae_a_la_suma_de_los_puestos(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $evento = $this->evento($tenant);
        $this->metaDelPuesto($tenant, $evento, $lugar, 300);

        $this->getJson('/api/v1/e14/proyeccion?nivel=global')
            ->assertOk()
            ->assertJsonPath('data.0.meta', 300)
            // Rotulado: quien lea el número tiene que saber de dónde salió.
            ->assertJsonPath('data.0.meta_origen', 'suma_de_puestos');
    }

    public function test_el_filtro_de_municipio_recorta_las_filas(): void
    {
        $tenant = $this->operador();
        $ibague = $this->puestoDelCatalogo();
        $espinal = $this->puestoDelCatalogo([
            'municipio_votacion' => 'ESPINAL',
            'puesto_votacion' => 'COLEGIO SAN JUAN',
        ]);

        $this->votantes($tenant, 50, ['voting_place_id' => $ibague->id]);
        $this->votantes($tenant, 10, [
            'voting_place_id' => $espinal->id,
            'municipio_votacion' => 'ESPINAL',
            'puesto_votacion' => 'COLEGIO SAN JUAN',
        ]);

        $this->evento($tenant);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto&municipio=ESPINAL')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.municipio', 'ESPINAL');
    }

    // ------------------------------------------------------------ totales

    public function test_los_totales_resumen_la_campana_y_dicen_de_donde_sale_la_meta(): void
    {
        $tenant = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $otro = $this->puestoDelCatalogo(['puesto_votacion' => self::OTRO_LUGAR]);
        $this->votantes($tenant, 50, ['voting_place_id' => $lugar->id]);
        $this->votantes($tenant, 30, [
            'voting_place_id' => $otro->id,
            'puesto_votacion' => self::OTRO_LUGAR,
        ]);
        $this->cargarActa();
        $evento = $this->evento($tenant, 2, 1000);
        $this->metaDelPuesto($tenant, $evento, $lugar, 300);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            ->assertJsonPath('meta.totales.meta', 1000)
            ->assertJsonPath('meta.totales.meta_origen', 'global')
            ->assertJsonPath('meta.totales.meta_asignada', 300)
            ->assertJsonPath('meta.totales.identificados', 80)
            ->assertJsonPath('meta.totales.votos_reales', 30)
            ->assertJsonPath('meta.totales.faltante', 970)
            ->assertJsonPath('meta.totales.puestos', 2)
            // El puesto al que nadie le puso meta se reporta: sin eso, un
            // tablero medio configurado se lee como uno completo.
            ->assertJsonPath('meta.totales.puestos_sin_meta', 1);
    }

    public function test_la_meta_del_candidato_viaja_como_en_el_cruce(): void
    {
        $tenant = $this->operador();
        $this->puestoDelCatalogo();
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/proyeccion')
            ->assertOk()
            ->assertJsonPath('meta.candidato.numero', 2)
            ->assertJsonPath('meta.candidato.es_corporacion', false)
            ->assertJsonPath('meta.nivel', 'puesto')
            ->assertJsonPath('meta.incluir', 'voters');
    }

    // ------------------------------------------------------- corporación

    public function test_en_corporacion_los_votos_reales_son_los_de_lista_y_preferente(): void
    {
        $tenant = $this->operador(tenant: Tenant::factory()->corporacion()->create());
        $lugar = $this->puestoDelCatalogo(['puesto_votacion' => 'UNIVERSIDAD COOPERATIVA']);
        $this->votantes($tenant, 50, [
            'voting_place_id' => $lugar->id,
            'puesto_votacion' => 'UNIVERSIDAD COOPERATIVA',
        ]);

        // Dos listas con el **mismo** preferente 5: la mía (11) saca 30, la otra
        // (1) saca 99. Sumar el 5 de todas las listas sería contarle a mi
        // candidato los votos de un rival.
        $this->postJson('/api/v1/e14/actas', [
            'tipo' => E14Acta::TIPO_CONCEJO,
            'estado' => 'procesada',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '005',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => 'UNIVERSIDAD COOPERATIVA',
            'listas' => [
                [
                    'lista_numero' => 11,
                    'lista_nombre' => 'PARTIDO CENTRO DEMOCRÁTICO',
                    'votos_solo_lista' => 4,
                    'total_agrupacion' => 36,
                    'con_voto_preferente' => true,
                    'preferentes' => [
                        ['numero' => 5, 'votos' => 30],
                        ['numero' => 7, 'votos' => 2],
                    ],
                ],
                [
                    'lista_numero' => 1,
                    'lista_nombre' => 'OTRO PARTIDO',
                    'votos_solo_lista' => 5,
                    'total_agrupacion' => 105,
                    'con_voto_preferente' => true,
                    'preferentes' => [
                        ['numero' => 5, 'votos' => 99],
                        ['numero' => 9, 'votos' => 1],
                    ],
                ],
            ],
            'votos_blanco' => 4,
            'votos_nulos' => 4,
            'votos_no_marcados' => 4,
            'votos_urna' => 153,
            'votantes_e11' => 153,
        ])->assertSuccessful();

        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('tipo', E14Acta::TIPO_CONCEJO)
            ->firstOrFail();

        $evento->update([
            'candidato_propio_lista_numero' => 11,
            'candidato_propio_numero' => 5,
            'candidato_propio_nombre' => 'ANA RUIZ',
            'candidato_propio_agrupacion' => 'PARTIDO CENTRO DEMOCRÁTICO',
        ]);

        $this->metaDelPuesto($tenant, $evento, $lugar, 100);

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto&event='.$evento->id)
            ->assertOk()
            ->assertJsonPath('data.0.votos_reales', 30)
            ->assertJsonPath('data.0.avance_real', 30)
            ->assertJsonPath('data.0.faltante', 70)
            ->assertJsonPath('meta.candidato.es_corporacion', true)
            ->assertJsonPath('meta.candidato.lista_numero', 11);
    }

    // -------------------------------------------------------- las puertas

    public function test_sin_candidato_propio_responde_422_como_el_cruce(): void
    {
        $tenant = $this->operador();
        $this->puestoDelCatalogo();
        $this->evento($tenant, null);

        $this->getJson('/api/v1/e14/proyeccion')
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }

    public function test_en_corporacion_medio_candidato_tampoco_abre_la_puerta(): void
    {
        $tenant = $this->operador(tenant: Tenant::factory()->corporacion()->create());

        $evento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => E14Acta::TIPO_CONCEJO,
            'nombre' => 'Concejo',
            'fecha' => '2027-10-31',
            // Preferente sin lista: media configuración (0083).
            'candidato_propio_numero' => 5,
        ]);

        $this->getJson('/api/v1/e14/proyeccion?event='.$evento->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors('event');
    }

    public function test_la_proyeccion_pide_view_e14(): void
    {
        $tenant = $this->operador([Permissions::MANAGE_E14]);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/proyeccion')->assertForbidden();
    }

    public function test_un_nivel_que_no_existe_no_pasa(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/proyeccion?nivel=barrio')
            ->assertStatus(422)
            ->assertJsonValidationErrors('nivel');
    }

    // ------------------------------------------------------- multi-tenant

    public function test_la_proyeccion_no_ve_la_base_ni_la_meta_de_otra_campana(): void
    {
        $otro = Tenant::factory()->create();
        $suLugar = $this->puestoDelCatalogo(['puesto_votacion' => 'COLEGIO DEL OTRO']);
        Voter::factory()->count(500)->forTenant($otro)->create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO DEL OTRO',
            'voting_place_id' => $suLugar->id,
        ]);
        $suEvento = ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $otro->id,
            'tipo' => 'alcaldia',
            'nombre' => 'Alcaldía',
            'fecha' => '2027-10-31',
            'candidato_propio_numero' => 2,
            'meta_votos' => 99999,
        ]);
        E14MetaPuesto::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $otro->id,
            'electoral_event_id' => $suEvento->id,
            'voting_place_id' => $suLugar->id,
            'meta_votos' => 5000,
        ]);

        $mio = $this->operador();
        $lugar = $this->puestoDelCatalogo();
        $this->votantes($mio, 50, ['voting_place_id' => $lugar->id]);
        $evento = $this->evento($mio, 2, 1000);
        $this->metaDelPuesto($mio, $evento, $lugar, 300);

        $respuesta = $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')->assertOk()->json();

        $this->assertCount(1, $respuesta['data']);
        $this->assertSame(self::LUGAR, $respuesta['data'][0]['puesto']);
        $this->assertSame(50, $respuesta['meta']['totales']['identificados']);
        $this->assertSame(1000, $respuesta['meta']['totales']['meta']);
        $this->assertSame(300, $respuesta['meta']['totales']['meta_asignada']);
    }

    // ---------------------------------------------------------- sin N+1

    public function test_el_numero_de_consultas_no_crece_con_los_puestos(): void
    {
        $tenant = $this->operador();
        $evento = $this->evento($tenant, 2, 5000);

        foreach (range(1, 12) as $indice) {
            $lugar = $this->puestoDelCatalogo(['puesto_votacion' => "PUESTO {$indice}"]);
            $this->votantes($tenant, 2, [
                'voting_place_id' => $lugar->id,
                'puesto_votacion' => "PUESTO {$indice}",
            ]);
            $this->metaDelPuesto($tenant, $evento, $lugar, 100);
        }

        $consultas = 0;
        DB::listen(function () use (&$consultas) {
            $consultas++;
        });

        $this->getJson('/api/v1/e14/proyeccion?nivel=puesto')
            ->assertOk()
            ->assertJsonCount(12, 'data');

        // Las agregaciones son por evento, no por puesto: 12 puestos tienen que
        // costar lo mismo que 1. El margen cubre la sesión, el tenant y los
        // permisos, no una consulta por fila.
        $this->assertLessThan(25, $consultas, "La proyección hizo {$consultas} consultas: parece un N+1.");
    }
}
