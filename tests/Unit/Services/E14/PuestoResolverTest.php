<?php

namespace Tests\Unit\Services\E14;

use App\Services\E14\PuestoResolver;
use PHPUnit\Framework\TestCase;

/**
 * Normalización de la llave del cruce (Spec 0062 · Parte A).
 *
 * Se prueba sin base de datos porque es una decisión pura: qué diferencias entre
 * dos textos **no** cambian de qué sitio se habla. Lo que casa aquí se une solo;
 * lo que no, va a la pantalla de conciliación.
 */
class PuestoResolverTest extends TestCase
{
    public function test_norm_ignora_mayusculas_acentos_y_espacios_de_mas(): void
    {
        $esperado = 'colegio san simon';

        foreach (['COLEGIO SAN SIMON', 'Colegio San Simón', '  colegio   san  simon ', 'COLEGIO SAN SIMÓN'] as $variante) {
            $this->assertSame($esperado, PuestoResolver::norm($variante), $variante);
        }
    }

    public function test_norm_trata_la_enne_como_n(): void
    {
        // Quien teclea «LA PENA» y quien teclea «LA PEÑA» hablan del mismo colegio.
        $this->assertSame(
            PuestoResolver::norm('INSTITUCION LA PEÑA'),
            PuestoResolver::norm('institucion la pena')
        );
    }

    public function test_norm_no_toca_abreviaturas_ni_puntuacion(): void
    {
        // Unir «COL.» con «COLEGIO» sería adivinar; eso lo decide una persona.
        $this->assertNotSame(
            PuestoResolver::norm('COL. SAN SIMON'),
            PuestoResolver::norm('COLEGIO SAN SIMON')
        );
    }

    public function test_la_mesa_se_normaliza_a_entero(): void
    {
        // El acta trae `005`, el votante trae `5`: son la misma mesa.
        $this->assertSame(5, PuestoResolver::mesa('005'));
        $this->assertSame(5, PuestoResolver::mesa(' 5 '));
        $this->assertSame(5, PuestoResolver::mesa(5));
        $this->assertSame(12, PuestoResolver::mesa('012'));
    }

    public function test_una_mesa_que_no_se_leyo_no_es_la_mesa_cero(): void
    {
        // Si `0` contara como mesa, todo lo ilegible se agruparía en un mismo
        // renglón inventado.
        $this->assertNull(PuestoResolver::mesa(null));
        $this->assertNull(PuestoResolver::mesa(''));
        $this->assertNull(PuestoResolver::mesa('000'));
        $this->assertNull(PuestoResolver::mesa('0'));
    }

    /**
     * El lado autoritativo del votante entra por la misma puerta (Spec 0075).
     *
     * Se comprueba la firma y nada más porque el comportamiento necesita base de
     * datos y vive en `tests/Feature/Voters/VotanteVotingPlaceTest.php`. Lo que
     * fija esta prueba es el contrato de RF-1: el webhook de Registraduría no
     * tiene su propio resolvedor, tiene un método **de este** resolver.
     */
    public function test_expone_el_resolver_autoritativo_del_votante(): void
    {
        $metodo = new \ReflectionMethod(PuestoResolver::class, 'resolverRegistraduria');

        $this->assertTrue($metodo->isPublic());
        $this->assertSame(
            ['departamento', 'municipio', 'puesto'],
            array_map(fn (\ReflectionParameter $p) => $p->getName(), $metodo->getParameters())
        );
        $this->assertSame('?int', (string) $metodo->getReturnType());
    }

    public function test_la_clave_junta_municipio_y_puesto(): void
    {
        $this->assertSame(
            PuestoResolver::clave('IBAGUÉ', 'Colegio San Simón'),
            PuestoResolver::clave('ibague', 'COLEGIO SAN SIMON')
        );

        // Sin uno de los dos no hay llave: es lo que deja al registro «sin
        // conciliar» en vez de caer en un grupo equivocado.
        $this->assertNull(PuestoResolver::clave(null, 'COLEGIO SAN SIMON'));
        $this->assertNull(PuestoResolver::clave('IBAGUE', ''));
    }
}
