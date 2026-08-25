<?php

namespace Tests\Unit\Services\E14;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\E14\EventoResolver;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * El resolver no da de alta elecciones fuera del tipo de la campaña
 * (Spec 0093 · Parte D).
 *
 * `resolver()` es la puerta por la que una elección se **crea al vuelo**: la
 * carga desde el panel y la ingesta del lector la usan para no exigir que
 * alguien dé de alta la jornada la noche del escrutinio. Desde la 0093 el tipo
 * de la carga sale del tenant, así que esto es defensa en profundidad: si un
 * worker publicara un resultado con otro `tipo`, la elección de más se crearía
 * sola y la campaña acabaría con una jornada fantasma que ninguna pantalla
 * puede mirar —porque todas derivan el tipo del `tipo_cargo`—.
 *
 * Se rechaza con el mismo idioma que el resto del filtro por elección: un
 * `ValidationException` en español, coherente con el flujo de rechazo.
 */
class EventoResolverTipoTest extends TestCase
{
    private EventoResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new EventoResolver;
    }

    private function evento(Tenant $tenant, string $tipo, string $nombre = 'X'): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            'nombre' => $nombre,
            'fecha' => '2027-10-31',
        ]);
    }

    public function test_crea_la_eleccion_del_tipo_de_la_campana(): void
    {
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);

        $evento = $this->resolver->resolver(['tipo' => 'alcaldia'], $tenant->id);

        $this->assertSame('alcaldia', $evento->tipo);
        $this->assertSame($tenant->id, $evento->tenant_id);
    }

    public function test_no_crea_una_eleccion_de_otro_tipo(): void
    {
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);

        try {
            $this->resolver->resolver(['tipo' => 'gobernacion'], $tenant->id);
            $this->fail('Se esperaba que no se pudiera crear una elección fuera del tipo.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Gobernación', $e->getMessage());
            $this->assertStringContainsString('Alcaldía', $e->getMessage());
        }

        $this->assertSame(
            0,
            ElectoralEvent::withoutGlobalScope(TenantScope::class)->count(),
            'No puede quedar ninguna elección fantasma creada por el intento.'
        );
    }

    public function test_un_id_de_otra_eleccion_de_la_misma_campana_se_rechaza(): void
    {
        // Existe y es del tenant, pero es de otra elección: servirla dejaría el
        // acta colgando de una jornada que ninguna pantalla puede mirar.
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);
        $ajena = $this->evento($tenant, 'concejo', 'Concejo 2027');

        $this->expectException(ValidationException::class);

        $this->resolver->resolver(
            ['tipo' => 'alcaldia', 'electoral_event_id' => $ajena->id],
            $tenant->id,
        );
    }

    public function test_reusa_la_eleccion_que_ya_existe_en_vez_de_duplicarla(): void
    {
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Alcaldia']);
        $existente = $this->evento($tenant, 'alcaldia', 'Alcaldía');

        $evento = $this->resolver->resolver(['tipo' => 'alcaldia'], $tenant->id);

        $this->assertSame($existente->id, $evento->id);
        $this->assertSame(1, ElectoralEvent::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_una_campana_sin_eleccion_no_da_de_alta_ninguna(): void
    {
        // `Otro` no escruta: crearle una jornada sería inventarle un cargo.
        $tenant = Tenant::factory()->create(['tipo_cargo' => 'Otro']);

        $this->expectException(ValidationException::class);

        $this->resolver->resolver(['tipo' => 'alcaldia'], $tenant->id);
    }
}
