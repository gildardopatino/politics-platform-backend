<?php

namespace Tests\Unit\Services\E14;

use App\Models\E14Acta;
use App\Models\E14Resultado;
use App\Models\ElectoralEvent;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Services\E14\RendimientoLideresService;
use Tests\TestCase;

/**
 * La aritmética del scorecard, sin HTTP de por medio (Spec 0063 · Parte A).
 *
 * Dos decisiones concentran aquí casi todo el riesgo del informe y merecen
 * probarse solas: cómo se **pondera** el rendimiento de las mesas del líder por
 * la gente que puso en cada una, y dónde exactamente cae la raya de la señal de
 * «posible inflado». Un error en la primera hace comparables a líderes que no lo
 * son; uno en la segunda marca a quien no debía.
 */
class RendimientoLideresServiceTest extends TestCase
{
    private const LUGAR = 'COLEGIO SAN SIMON';

    private Tenant $tenant;

    private VotingPlace $lugar;

    private ElectoralEvent $evento;

    private RendimientoLideresService $servicio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        app()->instance('current_tenant_id', $this->tenant->id);

        $this->lugar = VotingPlace::create([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::LUGAR,
        ]);

        $this->evento = ElectoralEvent::create([
            'tenant_id' => $this->tenant->id,
            'tipo' => 'alcaldia',
            'nombre' => 'Alcaldía',
            'fecha' => '2027-10-31',
            'candidato_propio_numero' => 2,
            'candidato_propio_nombre' => 'JOHANA ARANDA',
        ]);

        $this->servicio = app(RendimientoLideresService::class);
    }

    private function lider(string $nombre): User
    {
        return User::factory()->forTenant($this->tenant)->create([
            'name' => $nombre,
            'is_team_leader' => true,
        ]);
    }

    /** Gente del líder que vota en esa mesa, movilizada en una reunión suya. */
    private function movilizar(User $lider, int $mesa, int $cuantos, string $prefijo): void
    {
        $reunion = Meeting::factory()->forTenant($this->tenant)->create([
            'planner_user_id' => $lider->id,
        ]);

        for ($i = 1; $i <= $cuantos; $i++) {
            $cedula = $prefijo.$mesa.'-'.$i;

            $votante = Voter::factory()->forTenant($this->tenant)->create([
                'cedula' => $cedula,
                'departamento_votacion' => 'TOLIMA',
                'municipio_votacion' => 'IBAGUE',
                'puesto_votacion' => self::LUGAR,
                'mesa_votacion' => (string) $mesa,
                'voting_place_id' => $this->lugar->id,
            ]);

            MeetingAttendee::create([
                'tenant_id' => $this->tenant->id,
                'meeting_id' => $reunion->id,
                'voter_id' => $votante->id,
                'cedula' => $cedula,
                'nombres' => 'ASISTENTE',
                'apellidos' => $cedula,
                'checked_in' => true,
            ]);
        }
    }

    /** Un acta procesada de esa mesa con los votos de mi candidato. */
    private function acta(int $mesa, int $mios): void
    {
        $acta = E14Acta::create([
            'tenant_id' => $this->tenant->id,
            'electoral_event_id' => $this->evento->id,
            'tipo' => 'alcaldia',
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'lugar' => self::LUGAR,
            'zona' => '01',
            'puesto' => '01',
            'mesa' => str_pad((string) $mesa, 3, '0', STR_PAD_LEFT),
            'voting_place_id' => $this->lugar->id,
            'estado' => E14Acta::ESTADO_PROCESADA,
        ]);

        E14Resultado::create([
            'tenant_id' => $this->tenant->id,
            'e14_acta_id' => $acta->id,
            'numero' => 2,
            'nombre' => 'JOHANA ARANDA',
            'votos' => $mios,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fila(): array
    {
        return $this->servicio->calcular($this->evento)['data'][0];
    }

    public function test_la_mesa_donde_puso_mas_gente_pesa_mas_en_su_rendimiento(): void
    {
        $lider = $this->lider('ANA LIDER');

        // 8 personas en una mesa que rindió al 25 %…
        $this->movilizar($lider, 5, 8, 'A');
        $this->acta(5, 2);

        // …y 2 en una que rindió al 100 %.
        $this->movilizar($lider, 7, 2, 'B');
        $this->acta(7, 2);

        $fila = $this->fila();

        // Promediar a secas daría 62,5 %: haría pasar por medio pelo a quien
        // movilizó ocho de cada diez personas donde no rindió.
        $this->assertSame(40.0, $fila['rendimiento_ponderado']);
        $this->assertSame(60.0, $fila['deficit_ponderado']);
        $this->assertSame(1, $fila['mesas_en_deficit']);
        $this->assertSame(0, $fila['mesas_en_superavit']);
        $this->assertSame(10, $fila['movilizados_identificados']);
    }

    public function test_la_mesa_sin_base_no_arrastra_el_promedio_pero_si_se_cuenta(): void
    {
        $lider = $this->lider('ANA LIDER');
        $this->movilizar($lider, 5, 4, 'A');
        $this->acta(5, 2);

        // Una mesa con acta y votos donde la campaña no tiene a nadie: no puede
        // haber gente del líder ahí, así que solo entra por el lado del cruce.
        $this->acta(9, 30);

        $fila = $this->fila();

        // 2/4 = 50 %, sin que la mesa ajena lo mueva.
        $this->assertSame(50.0, $fila['rendimiento_ponderado']);
        $this->assertSame(1, $fila['mesas_cubiertas']);
    }

    public function test_el_umbral_de_inflado_incluye_a_quien_cae_justo_en_la_raya(): void
    {
        config()->set('e14.rendimiento_lideres.umbral_movilizados', 4);
        config()->set('e14.rendimiento_lideres.umbral_deficit_ponderado', 50.0);

        $lider = $this->lider('ANA LIDER');
        $this->movilizar($lider, 5, 4, 'A');
        $this->acta(5, 2);

        $fila = $this->fila();

        // Exactamente 4 movilizados y exactamente 50 puntos de déficit: la raya
        // es «desde», no «por encima de».
        $this->assertSame(4, $fila['movilizados_identificados']);
        $this->assertSame(50.0, $fila['deficit_ponderado']);
        $this->assertTrue($fila['posible_inflado']);
    }

    public function test_un_solo_movilizado_por_debajo_del_umbral_no_se_marca(): void
    {
        config()->set('e14.rendimiento_lideres.umbral_movilizados', 5);
        config()->set('e14.rendimiento_lideres.umbral_deficit_ponderado', 50.0);

        $lider = $this->lider('ANA LIDER');
        $this->movilizar($lider, 5, 4, 'A');
        $this->acta(5, 0);

        $fila = $this->fila();

        // Peor rendimiento imposible (0 %) y aun así no se marca: sin volumen no
        // hay base que inflar, hay cuatro personas.
        $this->assertSame(100.0, $fila['deficit_ponderado']);
        $this->assertFalse($fila['posible_inflado']);
    }

    public function test_la_misma_persona_con_dos_lideres_cuenta_para_los_dos_y_una_sola_vez_en_el_total(): void
    {
        $ana = $this->lider('ANA LIDER');
        $beto = $this->lider('BETO LIDER');

        $votante = Voter::factory()->forTenant($this->tenant)->create([
            'cedula' => '111',
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::LUGAR,
            'mesa_votacion' => '5',
            'voting_place_id' => $this->lugar->id,
        ]);

        foreach ([$ana, $beto] as $lider) {
            $reunion = Meeting::factory()->forTenant($this->tenant)->create([
                'planner_user_id' => $lider->id,
            ]);

            MeetingAttendee::create([
                'tenant_id' => $this->tenant->id,
                'meeting_id' => $reunion->id,
                'voter_id' => $votante->id,
                'cedula' => '111',
                'nombres' => 'ASISTENTE',
                'apellidos' => 'UNO',
                'checked_in' => true,
            ]);
        }

        $this->acta(5, 1);

        $salida = $this->servicio->calcular($this->evento);

        // Los dos la movilizaron: ninguno de los dos miente…
        $this->assertSame(1, $salida['data'][0]['movilizados_identificados']);
        $this->assertSame(1, $salida['data'][1]['movilizados_identificados']);
        $this->assertSame(2, $salida['meta']['totales']['movilizados_por_lider']);

        // …pero la campaña movilizó a una persona, no a dos.
        $this->assertSame(1, $salida['meta']['totales']['movilizados_identificados']);
    }
}
