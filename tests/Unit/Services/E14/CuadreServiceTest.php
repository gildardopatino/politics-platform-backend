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

    // ------------------------------------------------- el acta sin datos (0077)

    public function test_un_acta_sin_datos_no_cuadra_aunque_los_ceros_coincidan(): void
    {
        // El bug que la 0077 vino a cerrar: `0 = 0 = 0` satisface las dos
        // igualdades del cuadre, así que un acta que el lector no consiguió
        // transcribir salía «procesada» y entraba al consolidado sumando nada.
        $veredicto = $this->cuadre->evaluar(
            sumaCandidatos: 0,
            votosBlanco: 0,
            votosNulos: 0,
            votosNoMarcados: 0,
            sumaDeclarada: 0,
            votosUrna: 0,
            votantesE11: 0,
        );

        $this->assertFalse($veredicto->cuadra);
        $this->assertSame('revision_manual', $veredicto->estado);
        $this->assertStringContainsString('no tiene datos', $veredicto->motivo);
    }

    public function test_el_e11_no_rescata_el_cuadre_de_un_acta_sin_votos(): void
    {
        // Que el E-11 diga que se registraron 250 personas no convierte en
        // escrutinio un acta donde no hay un solo voto: sin votos no hay nada
        // que consolidar. La novedad de nivelación se anota igual.
        $veredicto = $this->cuadre->evaluar(
            sumaCandidatos: 0,
            votosBlanco: 0,
            votosNulos: 0,
            votosNoMarcados: 0,
            sumaDeclarada: 0,
            votosUrna: 0,
            votantesE11: 250,
        );

        $this->assertFalse($veredicto->cuadra);
        $this->assertSame('revision_manual', $veredicto->estado);
        $this->assertSame(250, $veredicto->difNivelacion);
    }

    public function test_un_solo_voto_en_cualquier_casilla_devuelve_las_reglas_de_siempre(): void
    {
        // El guardia es un caso de borde, no una regla nueva: en cuanto hay algo
        // que contar, manda la coincidencia de casillas/declarada/urna.
        $cuadra = $this->cuadre->evaluar(
            sumaCandidatos: 0,
            votosBlanco: 1,
            votosNulos: 0,
            votosNoMarcados: 0,
            sumaDeclarada: 1,
            votosUrna: 1,
            votantesE11: 1,
        );

        $this->assertTrue($cuadra->cuadra);
        $this->assertSame('procesada', $cuadra->estado);

        $noCuadra = $this->cuadre->evaluar(
            sumaCandidatos: 1,
            votosBlanco: 0,
            votosNulos: 0,
            votosNoMarcados: 0,
            sumaDeclarada: 0,
            votosUrna: 0,
            votantesE11: 0,
        );

        // Casillas con un voto y urna vacía es una inconsistencia de lectura, no
        // un acta en blanco: tiene que decir *qué* no coincide.
        $this->assertSame('inconsistente', $noCuadra->estado);
        $this->assertStringContainsString('no coincide', $noCuadra->motivo);
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
