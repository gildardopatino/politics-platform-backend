<?php

namespace Tests\Feature\E14;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Tests\TestCase;

/**
 * Deshacer lo que permitía la pantalla vieja (Spec 0080 · RF-5).
 *
 * La 0062 configuraba el candidato **por elección**, así que una campaña podía
 * quedar con número fijado en la alcaldía y también en el concejo. Con la 0080
 * el candidato es uno y vive en la elección del `tipo_cargo`; lo que quedó
 * fijado fuera de ahí es residuo, y el cruce de otra elección lo leería como si
 * fuera legítimo.
 *
 * La limpieza es **idempotente** —correrla dos veces da lo mismo— y en una base
 * recién sembrada no toca nada, que es lo que la deja entrar en
 * `migrate:fresh --seed` sin efecto.
 *
 * Un cargo que no mapea (`Otro`, `Representante`) se **salta**: sin saber cuál
 * es su elección legítima, borrar sería destruir el único candidato que la
 * campaña tiene, sin forma de volver a fijarlo. Se informa y se deja quieto.
 */
class E14LimpiezaCandidatoTest extends TestCase
{
    private const COMANDO = 'e14:candidato-unico';

    private function tenant(string $cargo = 'Alcaldia'): Tenant
    {
        return Tenant::factory()->create(['tipo_cargo' => $cargo, 'nombre' => 'MIGUEL ALCALDE']);
    }

    private function evento(
        Tenant $tenant,
        string $tipo,
        ?int $numero = null,
        string $fecha = '2027-10-31'
    ): ElectoralEvent {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'tipo' => $tipo,
            // El año va en el nombre: dos elecciones del mismo tipo en la misma
            // campaña chocarían en la clave natural (tenant + tipo + nombre).
            'nombre' => ucfirst($tipo).' '.substr($fecha, 0, 4),
            'fecha' => $fecha,
            'candidato_propio_numero' => $numero,
            'candidato_propio_nombre' => $numero === null ? null : 'MIGUEL ALCALDE',
            'candidato_propio_agrupacion' => $numero === null ? null : 'MOVIMIENTO X',
        ]);
    }

    private function recargar(ElectoralEvent $evento): ElectoralEvent
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)->findOrFail($evento->id);
    }

    public function test_borra_el_candidato_de_las_elecciones_que_no_son_la_del_cargo(): void
    {
        $tenant = $this->tenant('Alcaldia');
        $alcaldia = $this->evento($tenant, 'alcaldia', 7);
        $concejo = $this->evento($tenant, 'concejo', 9);

        $this->artisan(self::COMANDO)->assertSuccessful();

        // La del cargo conserva su número…
        $this->assertSame(7, $this->recargar($alcaldia)->candidato_propio_numero);

        // …y la otra queda en NULL, ficha completa.
        $limpia = $this->recargar($concejo);
        $this->assertNull($limpia->candidato_propio_numero);
        $this->assertNull($limpia->candidato_propio_nombre);
        $this->assertNull($limpia->candidato_propio_agrupacion);
    }

    public function test_con_varias_del_mismo_tipo_solo_sobrevive_la_mas_reciente(): void
    {
        $tenant = $this->tenant('Alcaldia');
        $vieja = $this->evento($tenant, 'alcaldia', 3, '2023-10-29');
        $reciente = $this->evento($tenant, 'alcaldia', 7, '2027-10-31');

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame(7, $this->recargar($reciente)->candidato_propio_numero);
        $this->assertNull($this->recargar($vieja)->candidato_propio_numero);
    }

    public function test_es_idempotente(): void
    {
        $tenant = $this->tenant('Alcaldia');
        $alcaldia = $this->evento($tenant, 'alcaldia', 7);
        $concejo = $this->evento($tenant, 'concejo', 9);

        $this->artisan(self::COMANDO)->assertSuccessful();
        $primera = $this->recargar($concejo)->updated_at;

        $this->artisan(self::COMANDO)->assertSuccessful();

        // La segunda pasada no encuentra nada que limpiar: ni cambia el dato ni
        // vuelve a escribir la fila.
        $this->assertSame(7, $this->recargar($alcaldia)->candidato_propio_numero);
        $this->assertNull($this->recargar($concejo)->candidato_propio_numero);
        $this->assertEquals($primera, $this->recargar($concejo)->updated_at);
    }

    public function test_en_una_base_limpia_no_toca_nada(): void
    {
        $tenant = $this->tenant('Alcaldia');
        $alcaldia = $this->evento($tenant, 'alcaldia', 7);
        $antes = $this->recargar($alcaldia)->updated_at;

        $this->artisan(self::COMANDO)->assertSuccessful();

        $this->assertSame(7, $this->recargar($alcaldia)->candidato_propio_numero);
        $this->assertEquals($antes, $this->recargar($alcaldia)->updated_at);
    }

    public function test_no_toca_las_elecciones_de_otra_campana(): void
    {
        $uno = $this->tenant('Alcaldia');
        $this->evento($uno, 'concejo', 9);

        $otro = $this->tenant('Concejo');
        $concejoAjeno = $this->evento($otro, 'concejo', 5);

        $this->artisan(self::COMANDO)->assertSuccessful();

        // Para el segundo tenant el concejo SÍ es la elección de su cargo.
        $this->assertSame(5, $this->recargar($concejoAjeno)->candidato_propio_numero);
    }

    public function test_una_campana_sin_cargo_mapeable_se_salta_en_vez_de_quedarse_sin_candidato(): void
    {
        $tenant = $this->tenant('Otro');
        $alcaldia = $this->evento($tenant, 'alcaldia', 7);

        $this->artisan(self::COMANDO)
            ->expectsOutputToContain('sin cargo')
            ->assertSuccessful();

        // Borrarlo la dejaría sin candidato y sin forma de volver a fijarlo:
        // primero hay que arreglar el cargo de la campaña.
        $this->assertSame(7, $this->recargar($alcaldia)->candidato_propio_numero);
    }

    public function test_se_puede_acotar_a_una_campana(): void
    {
        $uno = $this->tenant('Alcaldia');
        $concejoUno = $this->evento($uno, 'concejo', 9);

        $otro = $this->tenant('Alcaldia');
        $concejoOtro = $this->evento($otro, 'concejo', 9);

        $this->artisan(self::COMANDO, ['--tenant' => $uno->id])->assertSuccessful();

        $this->assertNull($this->recargar($concejoUno)->candidato_propio_numero);
        $this->assertSame(9, $this->recargar($concejoOtro)->candidato_propio_numero);
    }
}
