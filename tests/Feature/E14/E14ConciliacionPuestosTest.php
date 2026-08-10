<?php

namespace Tests\Feature\E14;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * Conciliación de puestos (Spec 0062 · Parte A).
 *
 * «COL. SAN SIMON» y «COLEGIO SAN SIMON» son el mismo colegio y dos renglones
 * distintos. El servidor **no** los une por parecido: los lista, sugiere, y
 * ejecuta lo que decida una persona. Fusionarlos hace que sus registros casen —y
 * la decisión vale también para las actas que lleguen después, que es lo que
 * distingue una fusión de un parche.
 */
class E14ConciliacionPuestosTest extends TestCase
{
    private const BUENO = 'COLEGIO SAN SIMON';

    private const VARIANTE = 'COL. SAN SIMON';

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
            'candidato_propio_nombre' => 'JOHANA ARANDA',
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
            'lugar' => self::BUENO,
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
            'puesto_votacion' => self::BUENO,
            'mesa_votacion' => '5',
        ], $atributos));
    }

    /**
     * El caso del mundo real: los votantes están registrados con una grafía y el
     * acta trae otra, así que el acta creó su propio renglón.
     *
     * @return array{0: VotingPlace, 1: VotingPlace} [el de los votantes, el del acta]
     */
    private function dosGrafias(Tenant $tenant): array
    {
        $deVotantes = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::VARIANTE,
        ]);

        $this->votantes($tenant, 50, [
            'voting_place_id' => $deVotantes->id,
            'puesto_votacion' => self::VARIANTE,
        ]);

        $this->cargarActa();
        $this->evento($tenant);

        $deActa = VotingPlace::where('puesto_votacion', self::BUENO)->firstOrFail();

        return [$deVotantes, $deActa];
    }

    // ------------------------------------------------------- la lista

    public function test_lista_el_puesto_que_tiene_acta_y_ningun_registrado(): void
    {
        $tenant = $this->operador();
        [$deVotantes, $deActa] = $this->dosGrafias($tenant);

        $this->getJson('/api/v1/e14/puestos-por-conciliar')
            ->assertStatus(200)
            ->assertJsonPath('data.0.origen', 'acta')
            ->assertJsonPath('data.0.voting_place_id', $deActa->id)
            ->assertJsonPath('data.0.puesto', self::BUENO)
            ->assertJsonPath('data.0.registrados', 0)
            ->assertJsonPath('data.0.actas', 1)
            // Sugerencia, no fusión: el candidato que tiene lo que a este le
            // falta, en el mismo municipio.
            ->assertJsonPath('data.0.sugerencias.0.voting_place_id', $deVotantes->id)
            ->assertJsonPath('data.0.sugerencias.0.registrados', 50)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_lista_el_nombre_de_votante_que_no_resuelve(): void
    {
        $tenant = $this->operador();
        $this->cargarActa();
        $this->evento($tenant);

        // Votantes sin `voting_place_id` y con una grafía que no está en el
        // catálogo: no entran en el cruce y no desaparecen.
        $this->votantes($tenant, 30, [
            'voting_place_id' => null,
            'puesto_votacion' => self::VARIANTE,
        ]);

        $this->getJson('/api/v1/e14/puestos-por-conciliar')
            ->assertStatus(200)
            ->assertJsonPath('data.0.origen', 'votante')
            ->assertJsonPath('data.0.voting_place_id', null)
            ->assertJsonPath('data.0.puesto', self::VARIANTE)
            ->assertJsonPath('data.0.registrados', 30)
            ->assertJsonPath('data.0.sugerencias.0.puesto', self::BUENO)
            ->assertJsonPath('meta.registrados_sin_conciliar', 30);
    }

    public function test_un_puesto_con_registrados_y_sin_acta_no_es_un_problema_de_nombres(): void
    {
        $tenant = $this->operador();
        $lugar = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::BUENO,
        ]);
        $this->votantes($tenant, 40, ['voting_place_id' => $lugar->id]);
        $this->evento($tenant);

        // Es un acta que todavía no ha llegado: sale en la cobertura del cruce,
        // no en esta pantalla. Meterlo aquí sería ruido la noche del escrutinio.
        $this->getJson('/api/v1/e14/puestos-por-conciliar')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/v1/e14/cruce')
            ->assertJsonPath('meta.cobertura.puestos_sin_acta', 1);
    }

    public function test_las_actas_sin_nombre_de_puesto_se_cuentan_pero_no_se_fusionan(): void
    {
        $tenant = $this->operador();
        $this->cargarActa(['lugar' => null]);
        $this->evento($tenant);

        // No hay nombre que unir: se arregla corrigiendo el acta.
        $this->getJson('/api/v1/e14/puestos-por-conciliar')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.actas_sin_puesto', 1);
    }

    // ------------------------------------------------------ la fusión

    public function test_fusionar_dos_grafias_hace_que_sus_registros_casen(): void
    {
        $tenant = $this->operador();
        [$deVotantes, $deActa] = $this->dosGrafias($tenant);

        // Antes: dos filas, cada una con la mitad de la historia.
        $this->getJson('/api/v1/e14/cruce')->assertJsonCount(2, 'data');

        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'origen_id' => $deVotantes->id,
            'destino_id' => $deActa->id,
        ])->assertStatus(200)
            ->assertJsonPath('data.voting_place_id', $deActa->id)
            ->assertJsonPath('data.votantes_movidos', 50);

        // Después: una sola fila, con registrados y votos juntos.
        $this->getJson('/api/v1/e14/cruce')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.voting_place_id', $deActa->id)
            ->assertJsonPath('data.0.registrados', 50)
            ->assertJsonPath('data.0.votos_candidato', 30)
            ->assertJsonPath('data.0.penetracion', 60)
            ->assertJsonPath('meta.cobertura.puestos_sin_registrados', 0)
            ->assertJsonPath('meta.cobertura.puestos_sin_acta', 0);

        $this->getJson('/api/v1/e14/puestos-por-conciliar')->assertJsonCount(0, 'data');
    }

    public function test_la_fusion_vale_para_las_actas_que_lleguen_despues(): void
    {
        $tenant = $this->operador();
        [$deVotantes, $deActa] = $this->dosGrafias($tenant);

        // Se fusiona hacia la grafía de los votantes, que es la contraria a la que
        // trae el acta: si no quedara guardada, la siguiente acta la desharía.
        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'origen_id' => $deActa->id,
            'destino_id' => $deVotantes->id,
        ])->assertStatus(200);

        $this->cargarActa(['mesa' => '006']);

        $this->getJson('/api/v1/e14/cruce')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.voting_place_id', $deVotantes->id)
            ->assertJsonPath('data.0.registrados', 50)
            ->assertJsonPath('data.0.votos_candidato', 60)
            ->assertJsonPath('data.0.actas', 2);
    }

    public function test_se_puede_fusionar_un_nombre_que_nunca_tuvo_renglon(): void
    {
        $tenant = $this->operador();
        $this->cargarActa();
        $this->evento($tenant);
        $this->votantes($tenant, 30, [
            'voting_place_id' => null,
            'puesto_votacion' => self::VARIANTE,
        ]);

        $deActa = VotingPlace::where('puesto_votacion', self::BUENO)->firstOrFail();

        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'municipio' => 'IBAGUE',
            'puesto' => self::VARIANTE,
            'destino_id' => $deActa->id,
        ])->assertStatus(200)
            ->assertJsonPath('data.nombre_fusionado', self::VARIANTE);

        $this->getJson('/api/v1/e14/cruce')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.registrados', 30)
            ->assertJsonPath('data.0.votos_candidato', 30)
            ->assertJsonPath('meta.cobertura.registrados_sin_conciliar', 0);
    }

    public function test_fusionar_dos_veces_deja_lo_mismo(): void
    {
        $tenant = $this->operador();
        [$deVotantes, $deActa] = $this->dosGrafias($tenant);

        $peticion = ['origen_id' => $deVotantes->id, 'destino_id' => $deActa->id];

        $this->postJson('/api/v1/e14/puestos/fusionar', $peticion)->assertStatus(200);
        $this->postJson('/api/v1/e14/puestos/fusionar', $peticion)
            ->assertStatus(200)
            // Ya no queda nada que mover: la primera se lo llevó todo.
            ->assertJsonPath('data.votantes_movidos', 0);

        $this->assertSame(1, \App\Models\E14PuestoAlias::count());

        $this->getJson('/api/v1/e14/cruce')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.registrados', 50);
    }

    public function test_una_cadena_de_fusiones_no_deja_registros_a_medio_camino(): void
    {
        $tenant = $this->operador();
        [$primera, $canonico] = $this->dosGrafias($tenant);

        $tercera = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEG SAN SIMON',
        ]);
        $this->votantes($tenant, 5, [
            'voting_place_id' => $tercera->id,
            'puesto_votacion' => 'COLEG SAN SIMON',
        ]);

        // C → A y después A → B: las tres tienen que acabar en B.
        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'origen_id' => $tercera->id, 'destino_id' => $primera->id,
        ])->assertStatus(200);

        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'origen_id' => $primera->id, 'destino_id' => $canonico->id,
        ])->assertStatus(200);

        $this->getJson('/api/v1/e14/cruce')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.voting_place_id', $canonico->id)
            ->assertJsonPath('data.0.registrados', 55);
    }

    // ------------------------------------------ validación y aislamiento

    public function test_un_puesto_no_se_fusiona_consigo_mismo(): void
    {
        $tenant = $this->operador();
        [, $deActa] = $this->dosGrafias($tenant);

        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'origen_id' => $deActa->id,
            'destino_id' => $deActa->id,
        ])->assertStatus(422)->assertJsonValidationErrors('origen_id');
    }

    public function test_sin_origen_hacen_falta_municipio_y_puesto(): void
    {
        $tenant = $this->operador();
        [, $deActa] = $this->dosGrafias($tenant);

        $this->postJson('/api/v1/e14/puestos/fusionar', ['destino_id' => $deActa->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['municipio', 'puesto']);
    }

    public function test_el_destino_tiene_que_existir(): void
    {
        $tenant = $this->operador();
        $this->evento($tenant);

        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'destino_id' => 9999,
            'municipio' => 'IBAGUE',
            'puesto' => self::VARIANTE,
        ])->assertStatus(422)->assertJsonValidationErrors('destino_id');
    }

    public function test_fusionar_exige_manage_e14(): void
    {
        $tenant = $this->operador();
        [$deVotantes, $deActa] = $this->dosGrafias($tenant);

        // Se relee la sesión con solo lectura.
        $this->operador([Permissions::VIEW_E14], $tenant);

        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'origen_id' => $deVotantes->id,
            'destino_id' => $deActa->id,
        ])->assertStatus(403);
    }

    public function test_ver_los_pendientes_exige_view_e14(): void
    {
        $tenant = $this->operador([Permissions::MANAGE_E14]);
        $this->evento($tenant);

        $this->getJson('/api/v1/e14/puestos-por-conciliar')->assertStatus(403);
    }

    public function test_la_fusion_de_una_campana_no_cambia_el_cruce_de_otra(): void
    {
        $ajeno = Tenant::factory()->create();
        $this->operador(tenant: $ajeno);
        [$deVotantes, $deActa] = $this->dosGrafias($ajeno);

        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'origen_id' => $deVotantes->id,
            'destino_id' => $deActa->id,
        ])->assertStatus(200);

        // La otra campaña usa las mismas dos grafías del catálogo global; su
        // cruce sigue partido hasta que ella misma decida fusionarlas.
        $propio = $this->operador();
        $this->votantes($propio, 8, [
            'voting_place_id' => $deVotantes->id,
            'puesto_votacion' => self::VARIANTE,
        ]);
        $this->cargarActa();
        $this->evento($propio);

        $this->getJson('/api/v1/e14/cruce')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');

        $this->assertSame(1, \App\Models\E14PuestoAlias::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_el_catalogo_global_no_se_borra_al_fusionar(): void
    {
        $tenant = $this->operador();
        [$deVotantes, $deActa] = $this->dosGrafias($tenant);

        $this->postJson('/api/v1/e14/puestos/fusionar', [
            'origen_id' => $deVotantes->id,
            'destino_id' => $deActa->id,
        ])->assertStatus(200);

        // `voting_places` es compartido entre campañas: borrar un renglón sería
        // cambiarle los datos a otro tenant sin que nadie lo pidiera.
        $this->assertDatabaseHas('voting_places', [
            'id' => $deVotantes->id,
            'deleted_at' => null,
        ]);
    }
}
