<?php

namespace Tests\Unit\Services\E14;

use App\Services\E14\EstadisticasService;
use PHPUnit\Framework\TestCase;

/**
 * El armado de la fila enriquecida (Spec 0092 · Parte A).
 *
 * La mitad del cruce ya está probada en su propia suite; lo que se prueba aquí
 * es la costura: pegarle a cada fila de mesa lo que el acta de **esa** mesa
 * sabe —zona, urna, sufragantes— sin confundirla con la de al lado.
 *
 * La llave es `voting_place_id + mesa`, no la mesa sola: el «002» del Colegio
 * San Simón y el «002» de la Escuela La Paz son dos mesas distintas, y sumarlas
 * daría un número que no es de ningún puesto. Por eso es una función pura y con
 * prueba propia: es exactamente el sitio donde un descuido se ve como un dato
 * plausible.
 */
class EstadisticasFilaTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $cambios
     * @return array<string, mixed>
     */
    private function filaDelCruce(array $cambios = []): array
    {
        return array_replace([
            'voting_place_id' => 7,
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'puesto' => 'COLEGIO SAN SIMON',
            'mesa' => 5,
            'base' => 50,
            'votos_candidato' => 30,
            'deficit' => 20,
            'excedente' => 0,
            'tiene_acta' => true,
            // Lo que el cruce trae de más y la estadística no publica.
            'rendimiento' => 60.0,
            'diferencia' => -20,
            'actas' => 1,
        ], $cambios);
    }

    public function test_pega_la_zona_la_urna_y_los_sufragantes_de_su_propia_mesa(): void
    {
        $filas = EstadisticasService::enriquecer(
            [$this->filaDelCruce()],
            ['7|5' => ['zona' => '01', 'votos_urna' => 111, 'votantes_e11' => 108]],
        );

        $this->assertSame([
            'voting_place_id' => 7,
            'departamento' => 'TOLIMA',
            'municipio' => 'IBAGUE',
            'zona' => '01',
            'puesto' => 'COLEGIO SAN SIMON',
            'mesa' => 5,
            'base' => 50,
            'votos_candidato' => 30,
            'deficit' => 20,
            'excedente' => 0,
            'tiene_acta' => true,
            'votos_urna' => 111,
            'votantes_e11' => 108,
        ], $filas[0]);
    }

    public function test_la_mesa_sin_lectura_va_en_cero_y_sin_zona(): void
    {
        $filas = EstadisticasService::enriquecer(
            [$this->filaDelCruce(['mesa' => 6, 'votos_candidato' => 0, 'tiene_acta' => false])],
            [],
        );

        $this->assertSame(0, $filas[0]['votos_urna']);
        $this->assertSame(0, $filas[0]['votantes_e11']);
        // Sin acta no hay zona que poner: un «0» ahí sería una zona inventada.
        $this->assertNull($filas[0]['zona']);
        $this->assertFalse($filas[0]['tiene_acta']);
        // La base identificada sigue estando: la mesa existe, lo que falta es el
        // escrutinio.
        $this->assertSame(50, $filas[0]['base']);
    }

    public function test_dos_mesas_homonimas_toman_cada_una_su_lectura(): void
    {
        $filas = EstadisticasService::enriquecer(
            [
                $this->filaDelCruce(['voting_place_id' => 7, 'mesa' => 2]),
                $this->filaDelCruce(['voting_place_id' => 9, 'mesa' => 2, 'puesto' => 'ESCUELA LA PAZ']),
            ],
            [
                '7|2' => ['zona' => '01', 'votos_urna' => 111, 'votantes_e11' => 108],
                '9|2' => ['zona' => '02', 'votos_urna' => 42, 'votantes_e11' => 41],
            ],
        );

        $this->assertSame('01', $filas[0]['zona']);
        $this->assertSame(111, $filas[0]['votos_urna']);
        $this->assertSame('02', $filas[1]['zona']);
        $this->assertSame(42, $filas[1]['votos_urna']);
    }

    public function test_no_publica_las_columnas_de_trabajo_del_cruce(): void
    {
        $filas = EstadisticasService::enriquecer([$this->filaDelCruce()], []);

        // `rendimiento` es un porcentaje del cruce y `actas` un conteo interno:
        // la página de estadísticas deriva lo suyo de base/votos, y publicar de
        // más es contrato que luego hay que sostener.
        $this->assertArrayNotHasKey('rendimiento', $filas[0]);
        $this->assertArrayNotHasKey('diferencia', $filas[0]);
        $this->assertArrayNotHasKey('actas', $filas[0]);
    }

    public function test_la_cobertura_cuenta_filas_con_acta_sobre_el_total(): void
    {
        $filas = EstadisticasService::enriquecer(
            [
                $this->filaDelCruce(['mesa' => 5]),
                $this->filaDelCruce(['mesa' => 6, 'tiene_acta' => false]),
                $this->filaDelCruce(['mesa' => 7, 'tiene_acta' => false]),
            ],
            [],
        );

        $this->assertSame(['total' => 3, 'con_acta' => 1], EstadisticasService::cobertura($filas));
    }
}
