<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\Tenant;
use App\Support\Permissions;
use Tests\TestCase;

/**
 * El desglose del consolidado agrupa con la geografía completa (Spec 0089 · A).
 *
 * El código de puesto («00»), el de zona («00») y hasta el nombre del lugar
 * («PUESTO CABECERA MUNICIPAL») **se repiten en cada municipio**: son
 * identificadores locales, no nacionales. Agrupar por el eje a secas sumaba en
 * una sola fila las cabeceras de municipios distintos, y el número que salía no
 * era de ningún puesto — en los datos reales, «Por lugar → PUESTO CABECERA
 * MUNICIPAL = 806» era la suma de varias.
 *
 * No es solo que confunda: **el total de la fila está mal**. Por eso la clave es
 * `(departamento, municipio, eje)`, y por eso cada fila viaja con su municipio y
 * su departamento: un total sin decir de dónde es no significa nada.
 */
class E14ConsolidadoGeografiaTest extends TestCase
{
    private function operador(?Tenant $tenant = null): Tenant
    {
        $tenant ??= Tenant::factory()->create();
        [$user, $token] = $this->createTenantWithUser(
            [Permissions::VIEW_E14, Permissions::MANAGE_E14],
            $tenant
        );

        $this->actingAsTenantUser($user, $token);

        return $tenant;
    }

    /**
     * Un acta de alcaldía que cuadra. Sus votos: `$votos` al candidato 1, más
     * los tres controles en cero, así que el total del acta es `$votos`.
     *
     * @param  array<string, mixed>  $cambios
     */
    private function cargarActa(int $votos, array $cambios = []): void
    {
        static $semilla = 0;
        $semilla++;

        $this->postJson('/api/v1/e14/actas', array_replace([
            'tipo' => 'alcaldia',
            'estado' => 'procesada',
            'archivo_hash' => str_pad((string) $semilla, 64, 'a', STR_PAD_LEFT),
            'zona' => '00',
            'puesto' => '00',
            'mesa' => str_pad((string) $semilla, 3, '0', STR_PAD_LEFT),
            'departamento_code' => '73',
            'departamento' => 'TOLIMA',
            'municipio_code' => '73024',
            'municipio' => 'ALPUJARRA',
            'lugar' => 'PUESTO CABECERA MUNICIPAL',
            'suma_declarada' => $votos,
            'votos_urna' => $votos,
            'votantes_e11' => $votos,
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'resultados' => [
                ['numero' => 1, 'nombre' => 'JOHANA ARANDA', 'votos' => $votos],
            ],
        ], $cambios))->assertSuccessful();
    }

    /** El acta de Ibagué: mismo código de puesto y zona, mismo nombre de lugar. */
    private function cargarActaDeIbague(int $votos, array $cambios = []): void
    {
        $this->cargarActa($votos, array_replace([
            'municipio_code' => '73001',
            'municipio' => 'IBAGUE',
        ], $cambios));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function desglose(string $eje): array
    {
        return $this->getJson('/api/v1/e14/consolidado')
            ->assertStatus(200)
            ->json("desglose.por_{$eje}");
    }

    // ------------------------------------------- el bug que se corrige

    public function test_el_mismo_puesto_en_dos_municipios_da_dos_filas(): void
    {
        $this->operador();

        // Las dos son «puesto 00»: el código es local al municipio.
        $this->cargarActa(270);
        $this->cargarActaDeIbague(176);

        $filas = $this->desglose('puesto');

        // Antes salía **una** fila de 446, que no era el total de ningún puesto.
        $this->assertCount(2, $filas);

        $totales = collect($filas)->pluck('total', 'municipio')->all();
        $this->assertSame(270, $totales['ALPUJARRA']);
        $this->assertSame(176, $totales['IBAGUE']);
    }

    public function test_cada_fila_dice_de_que_municipio_y_departamento_es(): void
    {
        $this->operador();
        $this->cargarActa(270);

        $fila = $this->desglose('puesto')[0];

        $this->assertSame('00', $fila['puesto']);
        $this->assertSame('ALPUJARRA', $fila['municipio']);
        $this->assertSame('TOLIMA', $fila['departamento']);
        // El nombre del puesto es funcional al código dentro del municipio, así
        // que viaja con él: «00» no le dice nada a nadie.
        $this->assertSame('PUESTO CABECERA MUNICIPAL', $fila['lugar']);
    }

    public function test_el_mismo_nombre_de_lugar_en_dos_municipios_no_se_funde(): void
    {
        $this->operador();

        // «PUESTO CABECERA MUNICIPAL» lo tienen casi todos los municipios.
        $this->cargarActa(270);
        $this->cargarActaDeIbague(176);

        $filas = $this->desglose('lugar');

        $this->assertCount(2, $filas);
        $this->assertSame('PUESTO CABECERA MUNICIPAL', $filas[0]['lugar']);
        $this->assertSame('PUESTO CABECERA MUNICIPAL', $filas[1]['lugar']);
        $this->assertEqualsCanonicalizing(
            ['ALPUJARRA', 'IBAGUE'],
            collect($filas)->pluck('municipio')->all()
        );
    }

    public function test_la_misma_zona_en_dos_municipios_tampoco_se_funde(): void
    {
        $this->operador();
        $this->cargarActa(270);
        $this->cargarActaDeIbague(176);

        $filas = $this->desglose('zona');

        $this->assertCount(2, $filas);
        $this->assertSame('00', $filas[0]['zona']);
        $this->assertSame('00', $filas[1]['zona']);
    }

    // ----------------------------------------------- el total no cambia

    public function test_la_suma_de_las_filas_es_el_total_del_consolidado(): void
    {
        $this->operador();
        $this->cargarActa(270);
        $this->cargarActaDeIbague(176);

        $total = $this->getJson('/api/v1/e14/consolidado')
            ->assertStatus(200)
            ->json('meta.total_candidatos');

        // Lo que cambió es el reparto, no la cuenta: si el total se moviera, el
        // arreglo habría creado o perdido votos.
        $this->assertSame(446, $total);

        foreach (['puesto', 'zona', 'lugar'] as $eje) {
            $this->assertSame(
                $total,
                (int) collect($this->desglose($eje))->sum('total'),
                "El desglose por {$eje} no suma el total del consolidado."
            );
        }
    }

    // ------------------------------------------------------------ bordes

    public function test_un_acta_sin_municipio_no_se_mezcla_con_las_ubicadas(): void
    {
        $this->operador();

        $this->cargarActa(270);
        // Acta con puesto pero sin ubicación leída: no se le puede atribuir a
        // ningún municipio, y meterla en el primero que aparezca sería inventar.
        $this->cargarActa(50, [
            'departamento' => null,
            'municipio' => null,
            'departamento_code' => null,
            'municipio_code' => null,
        ]);

        $filas = $this->desglose('puesto');

        $this->assertCount(2, $filas);

        $sinUbicar = collect($filas)->firstWhere('municipio', null);
        $this->assertNotNull($sinUbicar, 'El acta sin ubicación se fundió con las ubicadas.');
        $this->assertSame(50, $sinUbicar['total']);
        $this->assertNull($sinUbicar['departamento']);
    }

    public function test_dos_municipios_homonimos_de_departamentos_distintos_se_separan(): void
    {
        $this->operador();

        // No es teórico: hay municipios que se llaman igual en dos
        // departamentos, y por eso la clave lleva los dos.
        $this->cargarActa(270);
        $this->cargarActa(120, [
            'departamento_code' => '05',
            'departamento' => 'ANTIOQUIA',
            'municipio' => 'ALPUJARRA',
        ]);

        $filas = $this->desglose('lugar');

        $this->assertCount(2, $filas);
        $this->assertEqualsCanonicalizing(
            ['TOLIMA', 'ANTIOQUIA'],
            collect($filas)->pluck('departamento')->all()
        );
    }

    // ------------------------------------------------------ corporación

    public function test_el_consolidado_de_corporacion_no_cambia(): void
    {
        $tenant = $this->operador(Tenant::factory()->corporacion()->create());

        $this->postJson('/api/v1/e14/actas', [
            'tipo' => E14Acta::TIPO_CONCEJO,
            'estado' => 'procesada',
            'zona' => '00',
            'puesto' => '00',
            'mesa' => '001',
            'departamento_code' => '73',
            'departamento' => 'TOLIMA',
            'municipio_code' => '73024',
            'municipio' => 'ALPUJARRA',
            'lugar' => 'PUESTO CABECERA MUNICIPAL',
            'listas' => [[
                'lista_numero' => 11,
                'lista_nombre' => 'PARTIDO CENTRO DEMOCRÁTICO',
                'votos_solo_lista' => 2,
                'total_agrupacion' => 5,
                'con_voto_preferente' => true,
                'preferentes' => [['numero' => 5, 'votos' => 3]],
            ]],
            'votos_blanco' => 0,
            'votos_nulos' => 0,
            'votos_no_marcados' => 0,
            'votos_urna' => 5,
            'votantes_e11' => 5,
        ])->assertSuccessful();

        $respuesta = $this->getJson('/api/v1/e14/consolidado?tipo='.E14Acta::TIPO_CONCEJO)
            ->assertStatus(200);

        // La corporación reparte por lista y por preferente, no por geografía:
        // no tiene desglose que corregir, y el suyo sigue igual.
        $respuesta->assertJsonPath('meta.total_listas', 5)
            ->assertJsonPath('data.0.lista_numero', 11)
            ->assertJsonPath('preferentes.0.votos', 3);

        $this->assertNull($respuesta->json('desglose'));
    }

    // ------------------------------------------------------ multi-tenant

    public function test_el_desglose_no_ve_las_actas_de_otra_campana(): void
    {
        $otro = Tenant::factory()->create();
        $this->operador($otro);
        $this->cargarActaDeIbague(999);

        $mio = $this->operador();
        $this->cargarActa(270);

        $filas = $this->desglose('puesto');

        $this->assertCount(1, $filas);
        $this->assertSame('ALPUJARRA', $filas[0]['municipio']);
        $this->assertSame(270, $filas[0]['total']);
        $this->assertNotNull($mio);
    }
}
