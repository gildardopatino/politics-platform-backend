<?php

namespace Tests\Unit\Services\E14;

use App\Services\E14\CuadreService;
use App\Services\E14\ListaCuadrable;
use PHPUnit\Framework\TestCase;

/**
 * El cuadre de un acta de corporación, en dos niveles (Spec 0067 · Parte B).
 *
 * El uninominal tiene una sola igualdad que comprobar. La corporación tiene dos,
 * y la de arriba no sirve sin la de abajo:
 *
 *     por lista:  votos solo lista + Σ preferentes = total de la agrupación
 *     global:     Σ totales de agrupación + blanco + nulos + no marcados = urna
 *
 * El self-check por lista es lo que hace útil el error: un acta de diecisiete
 * agrupaciones que no cuadra por un voto no le dice nada a quien la revise, pero
 * «la lista 1 · PARTIDO LIBERAL COLOMBIANO» le señala la hoja exacta.
 *
 * **No se compara contra `suma_declarada`**: el formulario de corporación no
 * trae la casilla «SUMA TOTAL DEL ACTA E-14» que sí tiene el uninominal
 * (confirmado sobre el papel en 0067-A). Exigir que coincidiera con cero habría
 * mandado a revisión todas las actas de concejo.
 *
 * Los números son los del acta real transcrita por 0067-A
 * (`actas/concejo/zona_1_mesa001.pdf`): 88 de listas + 8 + 3 + 12 = 111 = urna.
 * Este servicio tiene que dar **el mismo veredicto** que el lector Python.
 */
class CuadreCorporacionTest extends TestCase
{
    private CuadreService $cuadre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cuadre = new CuadreService;
    }

    /**
     * Las diecisiete agrupaciones del acta de la muestra.
     *
     * @return array<int, ListaCuadrable>
     */
    private function listasDeLaMuestra(): array
    {
        return [
            new ListaCuadrable(29, 'NUEVA FUERZA DEMOCRÁTICA', 0, 3, 3),
            new ListaCuadrable(5170, 'IBAGUÈ INDEPENDIENTE', 0, 3, 3),
            new ListaCuadrable(1, 'PARTIDO LIBERAL COLOMBIANO', 2, 6, 8),
            new ListaCuadrable(2, 'PARTIDO CONSERVADOR COLOMBIANO', 3, 17, 20),
            // Sin voto preferente: su único renglón va en «solo lista».
            new ListaCuadrable(37, 'MOVIMIENTO POLITICO FUERZA CIUDADANA', 1, 0, 1),
            new ListaCuadrable(7, 'PARTIDO POLÍTICO MIRA', 0, 0, 0),
            new ListaCuadrable(6497, 'PARTIDO DE LA U', 0, 4, 4),
            new ListaCuadrable(4, 'PARTIDO ALIANZA VERDE', 1, 4, 5),
            new ListaCuadrable(5, 'AICO', 0, 2, 2),
            new ListaCuadrable(6, 'ASI', 0, 3, 3),
            new ListaCuadrable(2642, 'FIRME POR IBAGUE', 0, 11, 11),
            new ListaCuadrable(20, 'MOVIMIENTO SALVACIÓN NACIONAL', 0, 1, 1),
            new ListaCuadrable(3, 'PARTIDO CAMBIO RADICAL', 0, 3, 3),
            new ListaCuadrable(11, 'PARTIDO CENTRO DEMOCRÁTICO', 5, 15, 20),
            new ListaCuadrable(23, 'LIGA', 0, 3, 3),
            new ListaCuadrable(24, 'PARTIDO DEMÓCRATA COLOMBIANO', 0, 0, 0),
            new ListaCuadrable(16, 'MOVIMIENTO ALIANZA DEMOCRÁTICA AMPLIA', 0, 1, 1),
        ];
    }

    /**
     * @param  array<int, ListaCuadrable>|null  $listas
     */
    private function evaluar(
        ?array $listas = null,
        int $blanco = 8,
        int $nulos = 3,
        int $noMarcados = 12,
        int $urna = 111,
        int $e11 = 111,
        int $incinerados = 0,
    ) {
        return $this->cuadre->evaluarCorporacion(
            listas: $listas ?? $this->listasDeLaMuestra(),
            votosBlanco: $blanco,
            votosNulos: $nulos,
            votosNoMarcados: $noMarcados,
            votosUrna: $urna,
            votantesE11: $e11,
            votosIncinerados: $incinerados,
        );
    }

    // ------------------------------------------------------------ el global

    public function test_el_acta_de_la_muestra_cuadra(): void
    {
        $veredicto = $this->evaluar();

        $this->assertTrue($veredicto->cuadra);
        $this->assertSame('procesada', $veredicto->estado);
        $this->assertSame('', $veredicto->motivo);
        $this->assertSame(111, $veredicto->sumaCalculada);
        $this->assertSame(111, $veredicto->votosUrna);
        $this->assertSame(0, $veredicto->difNivelacion);
        $this->assertSame([], $veredicto->listasDescuadradas);
    }

    public function test_el_global_se_contrasta_contra_la_urna_nivelada(): void
    {
        // La misma acta (las listas y los controles suman 111), pero la urna
        // trae 112 y un voto incinerado: se contaron 111 (Spec 0088).
        $veredicto = $this->evaluar(urna: 112, incinerados: 1);

        $this->assertTrue($veredicto->cuadra);
        $this->assertSame('procesada', $veredicto->estado);
        $this->assertSame(112, $veredicto->votosUrna);
        $this->assertSame(111, $veredicto->urnaEfectiva);
        $this->assertSame(0, $veredicto->difNivelacion, '111 sufragantes contra 111 contados');
    }

    public function test_sin_descontar_los_incinerados_la_misma_acta_no_cuadraria(): void
    {
        // El control del control: si mandara la urna cruda, este acta —que es
        // correcta— acabaría en revisión por el voto que se incineró.
        $veredicto = $this->evaluar(urna: 112);

        $this->assertFalse($veredicto->cuadra);
        $this->assertStringContainsString('112', $veredicto->motivo);
    }

    public function test_mas_incinerados_que_urna_manda_el_acta_a_revision(): void
    {
        $veredicto = $this->evaluar(urna: 111, incinerados: 200);

        $this->assertFalse($veredicto->cuadra);
        $this->assertSame(0, $veredicto->urnaEfectiva);
        $this->assertStringContainsString(
            'más votos incinerados (200) que votos en la urna (111)',
            $veredicto->motivo
        );
    }

    public function test_la_suma_declarada_no_existe_en_corporacion(): void
    {
        // El papel no trae esa casilla: se reporta en cero y no se compara.
        $veredicto = $this->evaluar();

        $this->assertSame(0, $veredicto->sumaDeclarada);
        $this->assertTrue($veredicto->cuadra);
    }

    public function test_el_global_se_contrasta_contra_la_urna(): void
    {
        $veredicto = $this->evaluar(urna: 110);

        $this->assertFalse($veredicto->cuadra);
        $this->assertSame('inconsistente', $veredicto->estado);
        $this->assertStringContainsString('urna', $veredicto->motivo);
        $this->assertStringContainsString('111', $veredicto->motivo);
        $this->assertStringContainsString('110', $veredicto->motivo);
    }

    public function test_la_nivelacion_es_una_novedad_no_un_error(): void
    {
        // Alguien se registró y no depositó: se anota, no invalida el acta.
        $veredicto = $this->evaluar(e11: 113);

        $this->assertTrue($veredicto->cuadra);
        $this->assertSame(2, $veredicto->difNivelacion);
    }

    // ------------------------------------------------- el self-check por lista

    public function test_una_lista_que_no_cuadra_nombra_la_lista(): void
    {
        $listas = $this->listasDeLaMuestra();
        // Es el fallo real que la visión cometió en la primera acta que se leyó:
        // un voto fantasma en un renglón vacío del liberal.
        $listas[2] = new ListaCuadrable(1, 'PARTIDO LIBERAL COLOMBIANO', 2, 7, 8);

        $veredicto = $this->evaluar($listas);

        $this->assertFalse($veredicto->cuadra);
        $this->assertSame('inconsistente', $veredicto->estado);
        $this->assertStringContainsString('1 · PARTIDO LIBERAL COLOMBIANO', $veredicto->motivo);
        $this->assertStringContainsString('8', $veredicto->motivo);
        $this->assertStringContainsString('9', $veredicto->motivo);
        $this->assertSame(['1 · PARTIDO LIBERAL COLOMBIANO'], $veredicto->listasDescuadradas);
    }

    public function test_varias_listas_descuadradas_se_nombran_todas(): void
    {
        $listas = $this->listasDeLaMuestra();
        $listas[2] = new ListaCuadrable(1, 'PARTIDO LIBERAL COLOMBIANO', 2, 7, 8);
        $listas[13] = new ListaCuadrable(11, 'PARTIDO CENTRO DEMOCRÁTICO', 5, 14, 20);

        $veredicto = $this->evaluar($listas);

        $this->assertSame(
            ['1 · PARTIDO LIBERAL COLOMBIANO', '11 · PARTIDO CENTRO DEMOCRÁTICO'],
            $veredicto->listasDescuadradas,
        );
    }

    public function test_el_self_check_no_toca_el_total_global(): void
    {
        // La lista declara 8 y sus casillas suman 9. El global sigue contando lo
        // DECLARADO (88), que es lo que alguien tallaría del papel: recalcularlo
        // taparía el error en vez de señalarlo.
        $listas = $this->listasDeLaMuestra();
        $listas[2] = new ListaCuadrable(1, 'PARTIDO LIBERAL COLOMBIANO', 2, 7, 8);

        $veredicto = $this->evaluar($listas);

        $this->assertSame(111, $veredicto->sumaCalculada);
        // Un solo motivo: el de la lista. El global sí cuadra.
        $this->assertStringNotContainsString('urna', $veredicto->motivo);
    }

    public function test_una_lista_sin_preferentes_cuadra_con_su_renglon_unico(): void
    {
        $veredicto = $this->evaluar([
            new ListaCuadrable(37, 'FUERZA CIUDADANA', 5, 0, 5),
        ], blanco: 0, nulos: 0, noMarcados: 0, urna: 5, e11: 5);

        $this->assertTrue($veredicto->cuadra);
    }

    public function test_una_lista_en_blanco_cuadra_en_cero(): void
    {
        // Existía en el tarjetón y no sacó un voto: no es un error.
        $veredicto = $this->evaluar([
            new ListaCuadrable(24, 'PARTIDO DEMÓCRATA COLOMBIANO', 0, 0, 0),
            new ListaCuadrable(11, 'PARTIDO CENTRO DEMOCRÁTICO', 0, 5, 5),
        ], blanco: 0, nulos: 0, noMarcados: 0, urna: 5, e11: 5);

        $this->assertTrue($veredicto->cuadra);
        $this->assertSame([], $veredicto->listasDescuadradas);
    }

    // ---------------------------------------------------- el acta sin datos

    public function test_un_acta_sin_un_solo_voto_va_a_revision(): void
    {
        // El guardia de la 0077, al nivel de la corporación: si no hay nada que
        // contar, no hay nada que cuadrar. `0 = 0` lo cumpliría en silencio.
        $veredicto = $this->evaluar([], blanco: 0, nulos: 0, noMarcados: 0, urna: 0, e11: 0);

        $this->assertFalse($veredicto->cuadra);
        $this->assertSame('revision_manual', $veredicto->estado);
        $this->assertSame(CuadreService::MOTIVO_SIN_DATOS, $veredicto->motivo);
    }

    public function test_listas_todas_en_cero_pero_con_urna_no_es_un_acta_vacia(): void
    {
        // La urna dice 40 y las casillas no suman nada: eso no es «sin datos»,
        // es un acta que no cuadra y hay que decir por qué.
        $veredicto = $this->evaluar([
            new ListaCuadrable(11, 'PARTIDO CENTRO DEMOCRÁTICO', 0, 0, 0),
        ], blanco: 0, nulos: 0, noMarcados: 0, urna: 40, e11: 40);

        $this->assertFalse($veredicto->cuadra);
        $this->assertSame('inconsistente', $veredicto->estado);
        $this->assertStringContainsString('urna', $veredicto->motivo);
    }

    public function test_solo_controles_sin_listas_no_es_un_acta_vacia(): void
    {
        $veredicto = $this->evaluar([], blanco: 3, nulos: 0, noMarcados: 0, urna: 3, e11: 3);

        $this->assertTrue($veredicto->cuadra);
        $this->assertSame(3, $veredicto->sumaCalculada);
    }

    // ------------------------------------------------- el uninominal intacto

    public function test_la_rama_uninominal_no_cambio(): void
    {
        $veredicto = $this->cuadre->evaluar(
            sumaCandidatos: 99,
            votosBlanco: 4,
            votosNulos: 4,
            votosNoMarcados: 4,
            sumaDeclarada: 111,
            votosUrna: 111,
            votantesE11: 111,
        );

        $this->assertTrue($veredicto->cuadra);
        $this->assertSame(111, $veredicto->sumaDeclarada);
        // El campo nuevo existe pero en uninominal siempre va vacío.
        $this->assertSame([], $veredicto->listasDescuadradas);
    }
}
