<?php

namespace App\Console\Commands;

use App\Models\ElectoralEvent;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use App\Services\E14\EventoResolver;
use Illuminate\Console\Command;

/**
 * Deja un solo candidato por campaña, el de su cargo (Spec 0080 · RF-5).
 *
 * La 0062 configuraba el candidato **por elección**, así que la pantalla vieja
 * permitía fijar número en la alcaldía y también en el concejo. Con la 0080 el
 * candidato es uno y vive en la elección del `tipo_cargo`: lo que quedó fuera de
 * ahí es residuo, y el cruce de esa otra elección lo leería como si fuera
 * legítimo.
 *
 * Es **idempotente** —escribe solo donde hay algo que borrar, así que la segunda
 * corrida no toca nada— y en una base recién sembrada no hace nada, que es lo que
 * la deja correr dentro de `migrate:fresh --seed` sin efecto.
 *
 * Una campaña cuyo `tipo_cargo` no mapea a un tipo de elección (`Otro`,
 * `Representante`) se **salta**: sin saber cuál es su elección legítima, borrar
 * sería destruir el único candidato que tiene y dejarla sin forma de volver a
 * fijarlo —el endpoint también la rechazaría—. Se informa para que alguien
 * arregle el cargo, que es la causa.
 */
class LimpiarCandidatoFueraDelCargo extends Command
{
    protected $signature = 'e14:candidato-unico
                            {--tenant= : Solo esta campaña (id del tenant)}';

    protected $description = 'Borra el candidato propio de las elecciones que no son la del cargo de la campaña (Spec 0080)';

    public function __construct(private readonly EventoResolver $eventos)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No hay campañas que recorrer.');

            return self::SUCCESS;
        }

        $limpiadas = 0;
        $saltadas = 0;

        foreach ($tenants as $tenant) {
            $delCargo = $this->eventos->delCargo($tenant);

            if ($this->eventos->tipoDelCargo($tenant->tipo_cargo) === null) {
                $saltadas++;
                $this->warn(
                    "· {$tenant->slug}: sin cargo de elección popular («".($tenant->tipo_cargo ?: 'sin cargo').'»); '
                    .'se deja como está hasta que se configure el cargo de la campaña.'
                );

                continue;
            }

            $borradas = $this->limpiar($tenant, $delCargo?->id);

            if ($borradas > 0) {
                $limpiadas += $borradas;
                $this->line("· {$tenant->slug}: {$borradas} elección(es) sin relación con su cargo, limpiadas.");
            }
        }

        $this->info("Listo. {$limpiadas} elección(es) limpiada(s), {$saltadas} campaña(s) saltada(s).");

        return self::SUCCESS;
    }

    /**
     * El `whereNotNull` es lo que hace la corrida idempotente: sin él, cada pasada
     * volvería a escribir filas que ya estaban en NULL y les movería el
     * `updated_at` sin haber cambiado nada.
     *
     * Sin `TenantScope` y con `tenant_id` explícito: en consola no hay tenant en
     * el contenedor, así que el scope no filtraría y esto recorrería la base
     * entera por campaña.
     */
    private function limpiar(Tenant $tenant, ?int $idDelCargo): int
    {
        return ElectoralEvent::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->when($idDelCargo !== null, fn ($q) => $q->whereKeyNot($idDelCargo))
            ->where(function ($q) {
                $q->whereNotNull('candidato_propio_numero')
                    ->orWhereNotNull('candidato_propio_nombre')
                    ->orWhereNotNull('candidato_propio_agrupacion');
            })
            ->update([
                'candidato_propio_numero' => null,
                'candidato_propio_nombre' => null,
                'candidato_propio_agrupacion' => null,
            ]);
    }
}
