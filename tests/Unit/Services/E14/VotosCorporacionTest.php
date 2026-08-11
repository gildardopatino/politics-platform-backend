<?php

namespace Tests\Unit\Services\E14;

use App\Models\E14Acta;
use App\Models\E14ListaPreferente;
use App\Models\E14ListaResultado;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Models\VotingPlace;
use App\Services\E14\CruceService;
use App\Services\E14\PuestoResolver;
use App\Services\E14\VotosService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Los votos de mi candidato cuando la campaña es a una corporación (Spec 0083).
 *
 * En alcaldía «mis votos» son la fila del E-14 con mi número del tarjetón. En
 * concejo no: son la fila `(mi lista, mi preferente)`, y quedarse solo con el
 * preferente sumaría el 5 de **todas** las listas — que son otras personas.
 *
 * El resto del cruce no se entera: recibe la misma tabla de votos por puesto y
 * mesa que recibía antes, con la misma llave. Lo único que cambia es de dónde
 * salen los números.
 */
class VotosCorporacionTest extends TestCase
{
    private Tenant $tenant;

    private VotosService $votos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->corporacion()->create();

        // Sin petición HTTP nadie ata el tenant al contenedor, y sin eso
        // `TenantScope` no filtra: se ata aquí para probar lo mismo que corre
        // en producción.
        app()->instance('current_tenant_id', $this->tenant->id);

        $this->votos = app(VotosService::class);
    }

    private function evento(?int $lista = 11, ?int $preferente = 5, ?Tenant $tenant = null): ElectoralEvent
    {
        $tenant ??= $this->tenant;

        return ElectoralEvent::create([
            'tenant_id' => $tenant->id,
            'tipo' => E14Acta::TIPO_CONCEJO,
            'nombre' => 'Concejo '.$tenant->id,
            'fecha' => '2027-10-31',
            'candidato_propio_lista_numero' => $lista,
            'candidato_propio_numero' => $preferente,
            'candidato_propio_nombre' => 'ANA RUIZ',
        ]);
    }

    private function lugar(string $puesto = 'COLEGIO SAN SIMON'): VotingPlace
    {
        return VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => $puesto,
        ]);
    }

    /**
     * Un acta de corporación con sus listas y preferentes.
     *
     * @param  array<int, array{lista: int, preferentes: array<int, int>}>  $listas
     */
    private function acta(
        ElectoralEvent $evento,
        ?VotingPlace $lugar,
        string $mesa,
        array $listas,
        string $estado = E14Acta::ESTADO_PROCESADA,
        ?Tenant $tenant = null
    ): E14Acta {
        $tenant ??= $this->tenant;

        $acta = E14Acta::create([
            'tenant_id' => $tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => E14Acta::TIPO_CONCEJO,
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => 'COLEGIO SAN SIMON',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => $mesa,
            'voting_place_id' => $lugar?->id,
            'estado' => $estado,
        ]);

        foreach ($listas as $fila) {
            $lista = E14ListaResultado::create([
                'tenant_id' => $tenant->id,
                'e14_acta_id' => $acta->id,
                'lista_numero' => $fila['lista'],
                'lista_nombre' => 'PARTIDO '.$fila['lista'],
            ]);

            foreach ($fila['preferentes'] as $numero => $votos) {
                E14ListaPreferente::create([
                    'tenant_id' => $tenant->id,
                    'e14_acta_id' => $acta->id,
                    'e14_lista_resultado_id' => $lista->id,
                    'numero' => $numero,
                    'votos' => $votos,
                ]);
            }
        }

        return $acta;
    }

    // --------------------------------------------------- lo que sí cuenta

    public function test_cuenta_el_preferente_de_mi_lista(): void
    {
        $evento = $this->evento(lista: 11, preferente: 5);
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '001', [
            ['lista' => 11, 'preferentes' => [1 => 6, 5 => 30, 9 => 2]],
        ]);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, 1) => 30],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA),
        );
    }

    public function test_el_mismo_preferente_de_otra_lista_no_suma(): void
    {
        // Es el fallo que esta spec vino a arreglar: sin mirar la lista, el
        // cruce sumaría 30 + 99 y le atribuiría a mi candidato los votos de
        // otra persona que comparte número de preferencia.
        $evento = $this->evento(lista: 11, preferente: 5);
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '001', [
            ['lista' => 11, 'preferentes' => [5 => 30]],
            ['lista' => 1, 'preferentes' => [5 => 99]],
        ]);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, 1) => 30],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA),
        );
    }

    public function test_otro_preferente_de_mi_lista_tampoco(): void
    {
        $evento = $this->evento(lista: 11, preferente: 5);
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '001', [
            ['lista' => 11, 'preferentes' => [4 => 40, 5 => 30, 6 => 60]],
        ]);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, 1) => 30],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA),
        );
    }

    public function test_los_votos_solo_por_la_lista_no_son_mios(): void
    {
        // `votos_solo_lista` va al partido, no a ningún candidato: sumarlos
        // sería regalarle a uno los votos de la agrupación entera.
        $evento = $this->evento(lista: 11, preferente: 5);
        $lugar = $this->lugar();

        $acta = $this->acta($evento, $lugar, '001', [
            ['lista' => 11, 'preferentes' => [5 => 30]],
        ]);

        E14ListaResultado::where('e14_acta_id', $acta->id)->update(['votos_solo_lista' => 500]);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, 1) => 30],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA),
        );
    }

    // ------------------------------------------------------- la agrupación

    public function test_por_mesa_separa_cada_mesa_y_normaliza_los_ceros(): void
    {
        $evento = $this->evento();
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '005', [['lista' => 11, 'preferentes' => [5 => 30]]]);
        $this->acta($evento, $lugar, '7', [['lista' => 11, 'preferentes' => [5 => 12]]]);

        // `005` y `5` son la misma mesa, igual que en uninominal.
        $this->assertSame([
            PuestoResolver::claveMesa($lugar->id, 5) => 30,
            PuestoResolver::claveMesa($lugar->id, 7) => 12,
        ], $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA));
    }

    public function test_por_puesto_suma_todas_las_mesas_en_una_llave(): void
    {
        $evento = $this->evento();
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '005', [['lista' => 11, 'preferentes' => [5 => 30]]]);
        $this->acta($evento, $lugar, '007', [['lista' => 11, 'preferentes' => [5 => 12]]]);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, null) => 42],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_PUESTO),
        );
    }

    // ------------------------------------------------------ lo que se excluye

    public function test_un_acta_que_no_cuadra_no_entra(): void
    {
        $evento = $this->evento();
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '001', [['lista' => 11, 'preferentes' => [5 => 30]]]);
        $this->acta(
            $evento,
            $lugar,
            '002',
            [['lista' => 11, 'preferentes' => [5 => 99]]],
            E14Acta::ESTADO_INCONSISTENTE
        );

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, null) => 30],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_PUESTO),
        );
    }

    public function test_un_acta_sin_puesto_canonico_no_entra(): void
    {
        // Lo que no resuelve no se aproxima: se cuenta en la cobertura.
        $evento = $this->evento();
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '001', [['lista' => 11, 'preferentes' => [5 => 30]]]);
        $this->acta($evento, null, '002', [['lista' => 11, 'preferentes' => [5 => 99]]]);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, null) => 30],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_PUESTO),
        );
    }

    public function test_un_acta_de_otra_eleccion_no_entra(): void
    {
        $evento = $this->evento();
        $otro = ElectoralEvent::create([
            'tenant_id' => $this->tenant->id,
            'tipo' => E14Acta::TIPO_ASAMBLEA,
            'nombre' => 'Asamblea',
            'candidato_propio_lista_numero' => 11,
            'candidato_propio_numero' => 5,
        ]);

        $lugar = $this->lugar();
        $this->acta($evento, $lugar, '001', [['lista' => 11, 'preferentes' => [5 => 30]]]);
        $this->acta($otro, $lugar, '002', [['lista' => 11, 'preferentes' => [5 => 99]]]);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, null) => 30],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_PUESTO),
        );
    }

    public function test_un_preferente_que_no_aparece_en_ninguna_acta_da_vacio(): void
    {
        // Cero votos reales, no dato faltante: la cobertura de la 0076 es la que
        // dice si hubo acta.
        $evento = $this->evento(lista: 11, preferente: 19);
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '001', [['lista' => 11, 'preferentes' => [5 => 30]]]);

        $this->assertSame([], $this->votos->deMiCandidato($evento, CruceService::NIVEL_PUESTO));
    }

    // ---------------------------------------------------------- aislamiento

    public function test_no_ve_los_preferentes_de_otra_campana(): void
    {
        $ajeno = Tenant::factory()->corporacion()->create();
        $eventoAjeno = $this->evento(tenant: $ajeno);

        $evento = $this->evento();
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '001', [['lista' => 11, 'preferentes' => [5 => 30]]]);
        // Misma lista, mismo preferente, mismo puesto: otra campaña.
        $this->acta($eventoAjeno, $lugar, '001', [['lista' => 11, 'preferentes' => [5 => 99]]], tenant: $ajeno);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, null) => 30],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_PUESTO),
        );
    }

    // ------------------------------------------------------------ el coste

    public function test_es_una_sola_consulta_agregada(): void
    {
        // Igual que la uninominal: sin una consulta por lista ni por mesa.
        $evento = $this->evento();
        $lugar = $this->lugar();

        foreach (['001', '002', '003'] as $mesa) {
            $this->acta($evento, $lugar, $mesa, [
                ['lista' => 11, 'preferentes' => [5 => 3]],
                ['lista' => 1, 'preferentes' => [5 => 9]],
            ]);
        }

        DB::enableQueryLog();
        $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA);
        $consultas = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $consultas);
    }

    // ------------------------------------------------- el uninominal intacto

    public function test_un_evento_uninominal_sigue_leyendo_e14_resultados(): void
    {
        // La rama de corporación ni se toca: `lista_numero` es null.
        $uninominal = ElectoralEvent::create([
            'tenant_id' => $this->tenant->id,
            'tipo' => E14Acta::TIPO_ALCALDIA,
            'nombre' => 'Alcaldía',
            'candidato_propio_numero' => 2,
        ]);

        $lugar = $this->lugar();

        $acta = E14Acta::create([
            'tenant_id' => $this->tenant->id,
            'electoral_event_id' => $uninominal->id,
            'tipo' => E14Acta::TIPO_ALCALDIA,
            'zona' => '01',
            'puesto' => '01',
            'mesa' => '001',
            'voting_place_id' => $lugar->id,
            'estado' => E14Acta::ESTADO_PROCESADA,
        ]);

        \App\Models\E14Resultado::create([
            'tenant_id' => $this->tenant->id,
            'e14_acta_id' => $acta->id,
            'numero' => 2,
            'nombre' => 'JOHANA ARANDA',
            'votos' => 44,
        ]);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, null) => 44],
            $this->votos->deMiCandidato($uninominal, CruceService::NIVEL_PUESTO),
        );
    }
}
