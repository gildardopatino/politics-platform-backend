<?php

namespace Tests\Feature\E14;

use App\Models\E14Acta;
use App\Models\Tenant;
use App\Services\E14\EventoResolver;
use Database\Factories\TenantFactory;
use Tests\TestCase;

/**
 * El cargo de un tenant de prueba no se echa a suertes (Spec 0083 · RF-0).
 *
 * `TenantFactory` elegía `tipo_cargo` con `randomElement`. Daba igual mientras
 * el cargo no decidiera nada, pero desde la 0082 decide qué pide «mi candidato»
 * —un número suelto en uninominal, el par `(lista, preferente)` en corporación—
 * y desde esta spec decide también cómo cuenta el cruce. Un tenant que unas
 * veces sale Alcaldía y otras Concejo hace fallar la misma prueba una de cada
 * dos veces, y eso se vio como una intermitencia sin explicación al cerrar la
 * 0082-A.
 *
 * La regla que se fija aquí: **el defecto es uninominal y es fijo**, y quien
 * necesite otro cargo lo pide en el propio test.
 */
class TenantFactoryDeterministaTest extends TestCase
{
    public function test_el_cargo_por_defecto_siempre_es_el_mismo(): void
    {
        $cargos = Tenant::factory()->count(25)->create()->pluck('tipo_cargo')->unique();

        $this->assertSame([TenantFactory::CARGO_POR_DEFECTO], $cargos->values()->all());
    }

    public function test_el_cargo_por_defecto_es_uninominal(): void
    {
        // Que sea fijo no basta: tiene que ser el caso de referencia, porque es
        // el que heredan las decenas de pruebas que no hablan del cargo.
        $tipo = (new EventoResolver)->tipoDelCargo(TenantFactory::CARGO_POR_DEFECTO);

        $this->assertNotNull($tipo, 'el cargo por defecto tiene que mapear a una elección');
        $this->assertFalse(E14Acta::esCorporacion($tipo));
    }

    public function test_corporacion_es_un_estado_explicito(): void
    {
        $tenant = Tenant::factory()->corporacion()->create();

        $tipo = (new EventoResolver)->tipoDelCargo($tenant->tipo_cargo);

        $this->assertTrue(E14Acta::esCorporacion($tipo));
    }

    public function test_corporacion_admite_elegir_cual(): void
    {
        $tenant = Tenant::factory()->corporacion('Diputado')->create();

        $this->assertSame('Diputado', $tenant->tipo_cargo);
        $this->assertSame('asamblea_departamental', (new EventoResolver)->tipoDelCargo('Diputado'));
    }

    public function test_se_puede_pedir_un_cargo_cualquiera(): void
    {
        // Incluido uno que no mapea: es un caso que hay que poder montar.
        $tenant = Tenant::factory()->tipoCargo('Otro')->create();

        $this->assertSame('Otro', $tenant->tipo_cargo);
        $this->assertNull((new EventoResolver)->tipoDelCargo('Otro'));
    }

    public function test_el_atributo_explicito_sigue_mandando(): void
    {
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Gobernacion']);

        $this->assertSame('Gobernacion', $tenant->tipo_cargo);
    }
}
