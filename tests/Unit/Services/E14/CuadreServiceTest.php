<?php

namespace Tests\Unit\Services\E14;

use App\Services\E14\CuadreService;
use PHPUnit\Framework\TestCase;

/**
 * La regla del cuadre (Spec 0061).
 *
 * Es la que decide qué votos entran al consolidado, así que se prueba sola, sin
 * base de datos ni HTTP de por medio.
 */
class CuadreServiceTest extends TestCase
{
    private CuadreService $cuadre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cuadre = new CuadreService;
    }

    public function test_un_acta_que_cuadra_queda_procesada(): void
    {
        // Mesa 001 de la muestra: 99 a candidatos + 4 + 4 + 4 = 111 = 111 = 111.
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
        $this->assertSame('procesada', $veredicto->estado);
        $this->assertSame('', $veredicto->motivo);
        $this->assertSame(111, $veredicto->sumaCalculada);
        $this->assertSame(0, $veredicto->difNivelacion);
    }

    public function test_si_las_casillas_no_suman_lo_declarado_el_acta_es_inconsistente(): void
    {
        // Mesa 003 de la muestra: las casillas dan 197 y el acta declara 187.
        $veredicto = $this->cuadre->evaluar(
            sumaCandidatos: 177,
            votosBlanco: 12,
            votosNulos: 4,
            votosNoMarcados: 4,
            sumaDeclarada: 187,
            votosUrna: 187,
            votantesE11: 187,
        );

        $this->assertFalse($veredicto->cuadra);
        $this->assertSame('inconsistente', $veredicto->estado);
        $this->assertSame(197, $veredicto->sumaCalculada);
        $this->assertStringContainsString('197', $veredicto->motivo);
        $this->assertStringContainsString('187', $veredicto->motivo);
    }

    public function test_la_urna_tambien_tiene_que_coincidir(): void
    {
        $veredicto = $this->cuadre->evaluar(
            sumaCandidatos: 99,
            votosBlanco: 4,
            votosNulos: 4,
            votosNoMarcados: 4,
            sumaDeclarada: 111,
            votosUrna: 110,
            votantesE11: 111,
        );

        $this->assertFalse($veredicto->cuadra);
        $this->assertStringContainsString('urna', $veredicto->motivo);
    }

    public function test_los_dos_desajustes_se_reportan_juntos(): void
    {
        $veredicto = $this->cuadre->evaluar(
            sumaCandidatos: 99,
            votosBlanco: 4,
            votosNulos: 4,
            votosNoMarcados: 4,
            sumaDeclarada: 100,
            votosUrna: 105,
            votantesE11: 105,
        );

        $this->assertStringContainsString('; ', $veredicto->motivo);
    }

    public function test_una_novedad_de_nivelacion_no_invalida_el_acta(): void
    {
        // Mesa 004: 237 registrados, 236 votos. Alguien se registró y no votó.
        $veredicto = $this->cuadre->evaluar(
            sumaCandidatos: 220,
            votosBlanco: 8,
            votosNulos: 4,
            votosNoMarcados: 4,
            sumaDeclarada: 236,
            votosUrna: 236,
            votantesE11: 237,
        );

        $this->assertTrue($veredicto->cuadra);
        $this->assertSame('procesada', $veredicto->estado);
        $this->assertSame(1, $veredicto->difNivelacion);
    }

    public function test_mas_votos_que_votantes_da_una_nivelacion_negativa(): void
    {
        // No debe truncarse a cero: una urna con más votos que votantes
        // registrados es la anomalía que más importa poder ver.
        $veredicto = $this->cuadre->evaluar(
            sumaCandidatos: 100,
            votosBlanco: 0,
            votosNulos: 0,
            votosNoMarcados: 0,
            sumaDeclarada: 100,
            votosUrna: 100,
            votantesE11: 97,
        );

        $this->assertTrue($veredicto->cuadra);
        $this->assertSame(-3, $veredicto->difNivelacion);
    }
}
