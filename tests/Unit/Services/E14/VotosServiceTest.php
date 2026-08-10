<?php

namespace Tests\Unit\Services\E14;

use App\Models\E14Acta;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Models\VotingPlace;
use App\Services\E14\CruceService;
use App\Services\E14\PuestoResolver;
use App\Services\E14\VotosService;
use Tests\TestCase;

/**
 * El lado «real» del cruce, extraído para compartirlo (Specs 0062, 0076 y 0063).
 *
 * Cuántos votos sacó mi candidato en cada puesto/mesa lo preguntan ya dos
 * pantallas —el cruce y el rendimiento de líderes— y tiene que dar lo mismo en
 * las dos. Por eso el SQL y la llave viven en un solo sitio: dos copias que
 * normalizan la mesa distinto son dos informes que se contradicen.
 */
class VotosServiceTest extends TestCase
{
    private Tenant $tenant;

    private VotosService $votos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();

        // Sin petición HTTP nadie ata el tenant al contenedor, y sin eso
        // `TenantScope` no filtra: se ata aquí para probar lo mismo que corre
        // en producción.
        app()->instance('current_tenant_id', $this->tenant->id);

        $this->votos = app(VotosService::class);
    }

    private function evento(): ElectoralEvent
    {
        return ElectoralEvent::create([
            'tenant_id' => $this->tenant->id,
            'tipo' => 'alcaldia',
            'nombre' => 'Alcaldía',
            'fecha' => '2027-10-31',
            'candidato_propio_numero' => 2,
            'candidato_propio_nombre' => 'JOHANA ARANDA',
        ]);
    }

    private function lugar(): VotingPlace
    {
        return VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => 'COLEGIO SAN SIMON',
        ]);
    }

    /**
     * @param  array<int, array{numero: int, votos: int}>  $resultados
     */
    private function acta(ElectoralEvent $evento, ?VotingPlace $lugar, string $mesa, array $resultados, string $estado = E14Acta::ESTADO_PROCESADA): E14Acta
    {
        $acta = E14Acta::create([
            'tenant_id' => $this->tenant->id,
            'electoral_event_id' => $evento->id,
            'tipo' => 'alcaldia',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => 'COLEGIO SAN SIMON',
            'zona' => '01',
            'puesto' => '01',
            'mesa' => $mesa,
            'voting_place_id' => $lugar?->id,
            'estado' => $estado,
        ]);

        foreach ($resultados as $fila) {
            E14Resultado::create([
                'tenant_id' => $this->tenant->id,
                'e14_acta_id' => $acta->id,
                'numero' => $fila['numero'],
                'nombre' => 'CANDIDATO '.$fila['numero'],
                'votos' => $fila['votos'],
            ]);
        }

        return $acta;
    }

    public function test_por_mesa_separa_cada_mesa_y_normaliza_los_ceros_a_la_izquierda(): void
    {
        $evento = $this->evento();
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '005', [['numero' => 2, 'votos' => 30]]);
        $this->acta($evento, $lugar, '7', [['numero' => 2, 'votos' => 12]]);

        $votos = $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA);

        // `005` y `5` son la misma mesa, y la llave la escribe una sola función.
        $this->assertSame([
            PuestoResolver::claveMesa($lugar->id, 5) => 30,
            PuestoResolver::claveMesa($lugar->id, 7) => 12,
        ], $votos);
    }

    public function test_por_puesto_suma_todas_las_mesas_en_una_sola_llave(): void
    {
        $evento = $this->evento();
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '005', [['numero' => 2, 'votos' => 30]]);
        $this->acta($evento, $lugar, '7', [['numero' => 2, 'votos' => 12]]);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, null) => 42],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_PUESTO)
        );
    }

    public function test_solo_cuenta_a_mi_candidato_y_solo_en_actas_procesadas(): void
    {
        $evento = $this->evento();
        $lugar = $this->lugar();

        $this->acta($evento, $lugar, '5', [
            ['numero' => 1, 'votos' => 50],
            ['numero' => 2, 'votos' => 30],
        ]);

        // Una mesa que todavía no cuadró consigo misma no sirve para juzgar el
        // rendimiento de nadie.
        $this->acta($evento, $lugar, '6', [['numero' => 2, 'votos' => 99]], E14Acta::ESTADO_INCONSISTENTE);

        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, 5) => 30],
            $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA)
        );
    }

    public function test_el_acta_sin_puesto_no_cuelga_de_ninguna_llave(): void
    {
        $evento = $this->evento();

        $this->acta($evento, null, '5', [['numero' => 2, 'votos' => 30]]);

        $this->assertSame([], $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA));
    }

    public function test_las_actas_procesadas_se_cuentan_aparte_de_los_votos(): void
    {
        $evento = $this->evento();
        $lugar = $this->lugar();

        // Un puesto donde mi candidato sacó cero **tiene** acta: confundirlo con
        // uno sin escrutar sería leer un cero real como un dato que falta.
        $this->acta($evento, $lugar, '5', [['numero' => 1, 'votos' => 50]]);

        $clave = PuestoResolver::claveMesa($lugar->id, 5);

        $this->assertSame([$clave => 1], $this->votos->actasProcesadas($evento, CruceService::NIVEL_MESA));
        $this->assertSame([], $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA));
    }

    public function test_no_cuenta_los_votos_de_otra_campana(): void
    {
        $evento = $this->evento();
        $lugar = $this->lugar();
        $this->acta($evento, $lugar, '5', [['numero' => 2, 'votos' => 30]]);

        $otra = Tenant::factory()->create();
        $this->tenant = $otra;
        app()->instance('current_tenant_id', $otra->id);
        $ajena = $this->evento();
        $this->acta($ajena, $lugar, '5', [['numero' => 2, 'votos' => 999]]);

        // La elección ajena solo ve lo suyo…
        $this->assertSame(
            [PuestoResolver::claveMesa($lugar->id, 5) => 999],
            $this->votos->deMiCandidato($ajena, CruceService::NIVEL_MESA)
        );

        // …y preguntar por la elección de otro tenant desde este contexto no
        // devuelve sus votos: el scope acota las actas, no solo el evento.
        $this->assertSame([], $this->votos->deMiCandidato($evento, CruceService::NIVEL_MESA));
    }
}
