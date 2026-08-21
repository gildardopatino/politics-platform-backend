<?php

namespace App\Console\Commands;

use App\Jobs\Meetings\GenerateQRCodeJob;
use App\Models\Meeting;
use App\Models\Tenant;
use App\Scopes\TenantScope;
use Illuminate\Console\Command;

/**
 * Le pone código QR a las reuniones que se quedaron sin él (Spec 0087).
 *
 * El QR y el formulario público de check-in solo aparecen si la reunión tiene
 * `qr_code`: el `MeetingResource` deriva el SVG **bajo demanda** de ese código y
 * el panel condiciona los dos botones a que llegue. Pero el código lo generaba
 * solo el `store()` de la API, así que toda reunión creada por otra vía —la
 * factory, los seeders, un `migrate:fresh --seed`— nacía con el campo en nulo y
 * su organizador se quedaba sin QR que imprimir, aunque el circuito de check-in
 * funcionara perfecto en cuanto existiera uno.
 *
 * No hay un tercer generador: el trabajo lo hace `GenerateQRCodeJob`, el mismo
 * que ya existía, despachado en línea. Si algún día cambia cómo se arma el
 * código o dónde se guarda el SVG, cambia en un solo sitio.
 *
 * **Sin `TenantScope` y por eso con cuidado.** En consola no hay tenant en el
 * contenedor, así que el scope no filtraría nada: se recorre a propósito la base
 * entera y cada QR se genera con el slug de **su** campaña.
 *
 * Es idempotente —solo mira las que tienen el campo en nulo—, así que correrlo
 * dos veces no regenera ni un código, y `--dry-run` deja ver el alcance antes de
 * escribir.
 */
class BackfillMeetingsQr extends Command
{
    protected $signature = 'meetings:backfill-qr
                            {--dry-run : Cuenta lo que haría, sin escribir nada}';

    protected $description = 'Le asigna código QR (y con él, formulario público) a las reuniones que no lo tienen (Spec 0087)';

    public function handle(): int
    {
        $ensayo = (bool) $this->option('dry-run');

        $pendientes = $this->pendientesPorCampana();

        if ($pendientes->isEmpty()) {
            $this->info('Listo. 0 reunión(es) sin QR: no hay nada que rellenar.');

            return self::SUCCESS;
        }

        $slugs = Tenant::withTrashed()
            ->whereIn('id', $pendientes->keys())
            ->pluck('slug', 'id');

        foreach ($pendientes as $tenantId => $cuantas) {
            $slug = $slugs[$tenantId] ?? "tenant #{$tenantId}";
            $this->line("· {$slug}: {$cuantas} reunión(es) sin QR.");
        }

        if ($ensayo) {
            $this->info(
                "Ensayo: {$pendientes->sum()} reunión(es) sin QR en {$pendientes->count()} campaña(s). "
                .'No se escribió nada; corre el comando sin --dry-run para rellenarlas.'
            );

            return self::SUCCESS;
        }

        [$rellenadas, $campanas, $huerfanas] = $this->rellenar();

        if ($huerfanas > 0) {
            // Se informan y se dejan estar: sin campaña no hay slug con el que
            // nombrar el QR, y abortar el lote por ellas dejaría sin arreglo a
            // todas las demás.
            $this->warn("· {$huerfanas} reunión(es) sin campaña viva: se saltaron.");
        }

        $this->info("Listo. {$rellenadas} reunión(es) con QR nuevo en {$campanas} campaña(s).");

        return self::SUCCESS;
    }

    /**
     * Cuántas reuniones sin QR tiene cada campaña.
     *
     * El `SoftDeletingScope` sigue puesto a propósito: una reunión en la papelera
     * no tiene formulario que abrir, así que no se le inventa un QR.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function pendientesPorCampana(): \Illuminate\Support\Collection
    {
        return Meeting::withoutGlobalScope(TenantScope::class)
            ->whereNull('qr_code')
            ->selectRaw('tenant_id, COUNT(*) as total')
            ->groupBy('tenant_id')
            ->orderBy('tenant_id')
            ->pluck('total', 'tenant_id')
            ->map(fn ($total) => (int) $total);
    }

    /**
     * @return array{0: int, 1: int, 2: int} rellenadas, campañas, huérfanas
     */
    private function rellenar(): array
    {
        $rellenadas = 0;
        $huerfanas = 0;
        $campanas = [];

        Meeting::withoutGlobalScope(TenantScope::class)
            ->whereNull('qr_code')
            ->with('tenant')
            ->chunkById(100, function ($reuniones) use (&$rellenadas, &$huerfanas, &$campanas) {
                foreach ($reuniones as $reunion) {
                    if ($reunion->tenant === null) {
                        $huerfanas++;

                        continue;
                    }

                    // El job ya corta solo si el código existe, así que esto es
                    // seguro incluso si dos corridas se pisaran.
                    GenerateQRCodeJob::dispatchSync($reunion);

                    $rellenadas++;
                    $campanas[$reunion->tenant_id] = true;
                }
            });

        return [$rellenadas, count($campanas), $huerfanas];
    }
}
