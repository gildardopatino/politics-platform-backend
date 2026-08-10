<?php

namespace Tests\Feature\Console;

use App\Models\E14PuestoAlias;
use App\Models\Tenant;
use App\Models\Voter;
use App\Models\VotingPlace;
use App\Scopes\TenantScope;
use App\Services\E14\PuestoResolver;
use Tests\TestCase;

/**
 * Backfill de `voters.voting_place_id` (Spec 0075).
 *
 * Arregla lo que dejó el `firstOrCreate` crudo del webhook: votantes apuntando a
 * un renglón duplicado por una diferencia de tildes, mientras las actas apuntaban
 * al canónico. Un id equivocado **no-nulo** no lo rescata el resolver de respaldo
 * —solo mira los nulos—, así que hace falta re-resolverlos a mano una vez.
 *
 * Autoritativo como el webhook (puede crear el renglón que falte) e **idempotente**:
 * la segunda corrida no toca nada.
 */
class ReapuntarVotingPlaceTest extends TestCase
{
    private const CANONICO = 'COLEGIO SAN SIMON';

    private function puesto(array $cambios = []): VotingPlace
    {
        return VotingPlace::create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
        ], $cambios));
    }

    private function votante(Tenant $tenant, array $atributos = []): Voter
    {
        return Voter::factory()->forTenant($tenant)->create(array_replace([
            'departamento_votacion' => 'TOLIMA',
            'municipio_votacion' => 'IBAGUE',
            'puesto_votacion' => self::CANONICO,
        ], $atributos));
    }

    private function fusionar(Tenant $tenant, string $municipio, string $puesto, VotingPlace $destino): void
    {
        E14PuestoAlias::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'clave' => PuestoResolver::clave($municipio, $puesto),
            'voting_place_id' => $destino->id,
            'municipio' => $municipio,
            'puesto' => $puesto,
        ]);
    }

    private function refrescado(Voter $votante): Voter
    {
        return Voter::withoutGlobalScope(TenantScope::class)->findOrFail($votante->id);
    }

    public function test_reapunta_al_canonico_y_la_segunda_corrida_no_cambia_nada(): void
    {
        $tenant = Tenant::factory()->create();
        $canonico = $this->puesto();
        // El duplicado que dejó el `firstOrCreate` crudo: la misma escuela con
        // tilde y minúsculas.
        $duplicado = $this->puesto(['municipio_votacion' => 'Ibagué', 'puesto_votacion' => 'colegio san simón']);

        $votante = $this->votante($tenant, ['voting_place_id' => $duplicado->id]);

        $this->artisan('voters:reapuntar-voting-place')
            ->expectsOutputToContain('1')
            ->assertExitCode(0);

        $this->assertSame($canonico->id, $this->refrescado($votante)->voting_place_id);

        // Idempotente: nada que mover la segunda vez.
        $this->artisan('voters:reapuntar-voting-place')->assertExitCode(0);

        $this->assertSame($canonico->id, $this->refrescado($votante)->voting_place_id);
        $this->assertSame(2, VotingPlace::count());
    }

    public function test_crea_el_puesto_que_falta_como_hace_el_webhook(): void
    {
        $tenant = Tenant::factory()->create();
        $votante = $this->votante($tenant, ['voting_place_id' => null]);

        $this->artisan('voters:reapuntar-voting-place')->assertExitCode(0);

        $puesto = VotingPlace::sole();

        $this->assertSame(self::CANONICO, $puesto->puesto_votacion);
        $this->assertSame($puesto->id, $this->refrescado($votante)->voting_place_id);
    }

    public function test_cada_campana_usa_su_propia_fusion(): void
    {
        $canonico = $this->puesto();
        $variante = $this->puesto(['puesto_votacion' => 'COL. SAN SIMON']);

        $conFusion = Tenant::factory()->create();
        $sinFusion = Tenant::factory()->create();
        $this->fusionar($conFusion, 'IBAGUE', 'COL. SAN SIMON', $canonico);

        $deLaFusion = $this->votante($conFusion, ['puesto_votacion' => 'COL. SAN SIMON', 'voting_place_id' => null]);
        $ajeno = $this->votante($sinFusion, ['puesto_votacion' => 'COL. SAN SIMON', 'voting_place_id' => null]);

        $this->artisan('voters:reapuntar-voting-place')->assertExitCode(0);

        // En consola no hay tenant enlazado: si el comando no lo enlazara por
        // iteración, `alias()` no filtraría y la fusión de una campaña movería a
        // los votantes de la otra (Art. III).
        $this->assertSame($canonico->id, $this->refrescado($deLaFusion)->voting_place_id);
        $this->assertSame($variante->id, $this->refrescado($ajeno)->voting_place_id);
    }

    public function test_no_toca_al_votante_sin_municipio_o_sin_puesto(): void
    {
        $tenant = Tenant::factory()->create();

        $sinPuesto = $this->votante($tenant, ['puesto_votacion' => null, 'voting_place_id' => null]);
        $sinMunicipio = $this->votante($tenant, ['municipio_votacion' => null, 'voting_place_id' => null]);

        $this->artisan('voters:reapuntar-voting-place')->assertExitCode(0);

        $this->assertNull($this->refrescado($sinPuesto)->voting_place_id);
        $this->assertNull($this->refrescado($sinMunicipio)->voting_place_id);
        // Sin llave no se inventa un renglón del catálogo.
        $this->assertSame(0, VotingPlace::count());
    }

    public function test_la_opcion_tenant_limita_el_alcance(): void
    {
        $elegido = Tenant::factory()->create();
        $otro = Tenant::factory()->create();

        $suyo = $this->votante($elegido, ['voting_place_id' => null]);
        $ajeno = $this->votante($otro, ['voting_place_id' => null]);

        $this->artisan('voters:reapuntar-voting-place', ['--tenant' => $elegido->id])
            ->assertExitCode(0);

        $this->assertNotNull($this->refrescado($suyo)->voting_place_id);
        $this->assertNull($this->refrescado($ajeno)->voting_place_id);
    }
}
