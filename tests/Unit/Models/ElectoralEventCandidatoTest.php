<?php

namespace Tests\Unit\Models;

use App\Models\E14Acta;
use App\Models\ElectoralEvent;
use App\Models\Tenant;
use Tests\TestCase;

/**
 * Qué cuenta como «candidato propio configurado» (Specs 0062, 0082 y 0083).
 *
 * La puerta es una sola —la comparten el cruce y el rendimiento de líderes— y
 * desde la 0082 tiene dos formas, porque el candidato tiene dos formas: un
 * número del tarjetón en uninominal, el par `(lista, preferente)` en
 * corporación. Media configuración no abre: un preferente sin lista no
 * identifica a nadie, ya que ese número existe en todas las agrupaciones.
 */
class ElectoralEventCandidatoTest extends TestCase
{
    private function evento(string $tipo, ?int $lista, ?int $numero): ElectoralEvent
    {
        return new ElectoralEvent([
            'tenant_id' => Tenant::factory()->create()->id,
            'tipo' => $tipo,
            'nombre' => $tipo,
            'candidato_propio_lista_numero' => $lista,
            'candidato_propio_numero' => $numero,
        ]);
    }

    public function test_uninominal_con_numero_esta_configurado(): void
    {
        $evento = $this->evento(E14Acta::TIPO_ALCALDIA, lista: null, numero: 2);

        $this->assertTrue($evento->tieneCandidatoPropio());
        $this->assertFalse($evento->esDeCorporacion());
    }

    public function test_uninominal_sin_numero_no(): void
    {
        $this->assertFalse($this->evento(E14Acta::TIPO_ALCALDIA, lista: null, numero: null)->tieneCandidatoPropio());
    }

    public function test_a_uninominal_la_lista_no_le_hace_falta(): void
    {
        // Una lista suelta en un evento de alcaldía es ruido, no un requisito.
        $this->assertTrue($this->evento(E14Acta::TIPO_ALCALDIA, lista: 11, numero: 2)->tieneCandidatoPropio());
    }

    public function test_corporacion_necesita_las_dos_mitades(): void
    {
        $evento = $this->evento(E14Acta::TIPO_CONCEJO, lista: 11, numero: 5);

        $this->assertTrue($evento->tieneCandidatoPropio());
        $this->assertTrue($evento->esDeCorporacion());
    }

    public function test_corporacion_sin_lista_no_esta_configurada(): void
    {
        $this->assertFalse($this->evento(E14Acta::TIPO_CONCEJO, lista: null, numero: 5)->tieneCandidatoPropio());
    }

    public function test_corporacion_sin_preferente_tampoco(): void
    {
        $this->assertFalse($this->evento(E14Acta::TIPO_SENADO, lista: 11, numero: null)->tieneCandidatoPropio());
    }

    public function test_el_resumen_dice_de_que_forma_es(): void
    {
        $corporacion = $this->evento(E14Acta::TIPO_ASAMBLEA, lista: 11, numero: 5);
        $corporacion->candidato_propio_nombre = 'ANA RUIZ';
        $corporacion->candidato_propio_agrupacion = 'PARTIDO CENTRO DEMOCRÁTICO';

        $this->assertSame([
            'numero' => 5,
            'lista_numero' => 11,
            'nombre' => 'ANA RUIZ',
            'agrupacion' => 'PARTIDO CENTRO DEMOCRÁTICO',
            'es_corporacion' => true,
        ], $corporacion->resumenDelCandidato());

        // En uninominal la lista viene en null y el flag en false: el panel
        // rotula «número 2» y no «lista · preferente».
        $uninominal = $this->evento(E14Acta::TIPO_GOBERNACION, lista: null, numero: 2);

        $this->assertSame(
            ['numero' => 2, 'lista_numero' => null, 'nombre' => null, 'agrupacion' => null, 'es_corporacion' => false],
            $uninominal->resumenDelCandidato(),
        );
    }
}
