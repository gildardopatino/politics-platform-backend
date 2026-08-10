<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\Voter;
use App\Scopes\TenantScope;
use App\Services\E14\PuestoResolver;
use Illuminate\Console\Command;

/**
 * Re-resuelve `voters.voting_place_id` con el resolver normalizado (Spec 0075).
 *
 * Hasta la 0075 el webhook de Registraduría casaba el puesto por igualdad exacta
 * de cadenas, así que dos grafías del mismo colegio dejaban dos renglones en
 * `voting_places`: las actas apuntaban al canónico y los votantes al duplicado, y
 * el cruce fallaba en silencio. Un id equivocado **no-nulo** no lo arregla el
 * resolver de respaldo —solo mira los nulos—, así que hay que re-resolverlos una
 * vez.
 *
 * Es **autoritativo** como el webhook (puede dar de alta el renglón que falte, si
 * hay departamento) e **idempotente**: escribe solo cuando el id cambia, así que
 * la segunda corrida no toca nada y en una base recién sembrada no hace nada.
 */
class ReapuntarVotingPlaceVotantes extends Command
{
    protected $signature = 'voters:reapuntar-voting-place
                            {--tenant= : Solo esta campaña (id del tenant)}';

    protected $description = 'Re-resuelve el puesto de votación de los votantes con el resolver normalizado del E-14 (Spec 0075)';

    public function __construct(private readonly PuestoResolver $puestos)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($tenants->isEmpty()) {
            $this->warn('No hay campañas que recorrer.');

            return self::SUCCESS;
        }

        $enlaceAnterior = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;

        $total = ['reapuntados' => 0, 'sin_cambio' => 0, 'sin_resolver' => 0];

        foreach ($tenants as $tenant) {
            $cuenta = $this->reapuntarTenant($tenant);

            foreach ($cuenta as $clave => $valor) {
                $total[$clave] += $valor;
            }
        }

        // La consola no tiene petición que enlace el tenant, así que lo enlaza este
        // comando; se deja como estaba para no contaminar lo que venga después.
        app()->instance('current_tenant_id', $enlaceAnterior);

        $this->newLine();
        $this->info('Puestos de votación re-resueltos:');
        $this->table(
            ['Resultado', 'Votantes'],
            [
                ['Re-apuntados a otro puesto', $total['reapuntados']],
                ['Ya estaban en el correcto', $total['sin_cambio']],
                ['Sin puesto que resolver', $total['sin_resolver']],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Una campaña, con su tenant enlazado.
     *
     * El enlace es lo que hace que `TenantScope` acote los votantes **y** que
     * `PuestoResolver::alias()` use las fusiones de esta campaña y no las de otra:
     * sin él la consola no filtra nada y una fusión ajena movería estos votantes
     * (Constitución, Art. III).
     *
     * @return array{reapuntados: int, sin_cambio: int, sin_resolver: int}
     */
    private function reapuntarTenant(Tenant $tenant): array
    {
        app()->instance('current_tenant_id', $tenant->id);

        // Una sola carga de catálogo y alias por campaña: resolver votante a
        // votante con su propia consulta sería el N+1 que la spec prohíbe.
        $this->puestos->refrescar();

        $cuenta = ['reapuntados' => 0, 'sin_cambio' => 0, 'sin_resolver' => 0];

        Voter::query()
            ->whereNotNull('municipio_votacion')
            ->where('municipio_votacion', '!=', '')
            ->whereNotNull('puesto_votacion')
            ->where('puesto_votacion', '!=', '')
            ->select(['id', 'departamento_votacion', 'municipio_votacion', 'puesto_votacion', 'voting_place_id'])
            ->chunkById(500, function ($votantes) use (&$cuenta) {
                foreach ($votantes as $votante) {
                    $puesto = $this->puestos->resolverRegistraduria(
                        $votante->departamento_votacion,
                        $votante->municipio_votacion,
                        $votante->puesto_votacion,
                    );

                    if ($puesto === null) {
                        $cuenta['sin_resolver']++;

                        continue;
                    }

                    if ($puesto === $votante->voting_place_id) {
                        $cuenta['sin_cambio']++;

                        continue;
                    }

                    // `withoutGlobalScope` no hace falta: el tenant está enlazado y
                    // el votante ya salió de una consulta acotada.
                    $votante->voting_place_id = $puesto;
                    $votante->save();
                    $cuenta['reapuntados']++;
                }
            });

        if ($cuenta['reapuntados'] > 0) {
            $this->line("  {$tenant->name}: {$cuenta['reapuntados']} re-apuntados.");
        }

        return $cuenta;
    }
}
