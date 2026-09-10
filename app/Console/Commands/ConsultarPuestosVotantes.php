<?php

namespace App\Console\Commands;

use App\Jobs\Registraduria\ConsultarPuestoVotacionJob;
use App\Models\Tenant;
use App\Models\Voter;
use App\Services\Registraduria\RegistraduriaClient;
use Illuminate\Console\Command;

/**
 * Encola la consulta de Registraduría para los votantes que aún no tienen
 * puesto (Spec 0091).
 *
 * Reemplaza al `GET .../registraduria/pendientes` de n8n: antes una pieza
 * externa preguntaba por lotes quién faltaba; ahora el disparo normal es
 * automático al nacer el votante y esto es solo la red de seguridad — los
 * votantes anteriores a la spec, y los que se quedaron sin resolver porque el
 * servicio estuvo caído más de lo que duran los reintentos.
 *
 * **`--limit` es un tope de gasto**, no una paginación: cada consulta puede
 * acabar pagando un reCAPTCHA, así que acota la corrida entera y no cada
 * campaña. Es idempotente: quien ya tiene puesto no se encola, así que una
 * segunda corrida no repite a los ya resueltos.
 */
class ConsultarPuestosVotantes extends Command
{
    protected $signature = 'voters:consultar-puestos
                            {--tenant= : Solo esta campaña (id del tenant)}
                            {--limit= : Máximo de votantes a encolar en toda la corrida}';

    protected $description = 'Encola la consulta del puesto de votación en Registraduría para los votantes que no lo tienen (Spec 0091)';

    public function __construct(private readonly RegistraduriaClient $cliente)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->cliente->configurado()) {
            $this->error('No hay servicio de Registraduría configurado (REGISTRADURIA_SERVICE_URL).');

            return self::FAILURE;
        }

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($tenants->isEmpty()) {
            $this->warn('No hay campañas que recorrer.');

            return self::SUCCESS;
        }

        $limite = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;

        $enlaceAnterior = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;

        $encolados = 0;

        foreach ($tenants as $tenant) {
            if ($limite !== null && $encolados >= $limite) {
                break;
            }

            $restante = $limite === null ? null : $limite - $encolados;

            $delTenant = $this->encolarTenant($tenant, $restante);

            $encolados += $delTenant;

            if ($delTenant > 0) {
                $this->line("  {$tenant->name}: {$delTenant} encolados.");
            }
        }

        // La consola no tiene petición que enlace el tenant, así que lo enlaza
        // este comando; se deja como estaba para no contaminar lo que venga
        // después (Art. III).
        app()->instance('current_tenant_id', $enlaceAnterior);

        $this->newLine();
        $this->info("Consultas encoladas: {$encolados}.");

        return self::SUCCESS;
    }

    /**
     * Una campaña, con su tenant enlazado: es lo que hace que `TenantScope`
     * acote los votantes y que el Job nazca con el tenant correcto.
     */
    private function encolarTenant(Tenant $tenant, ?int $restante): int
    {
        if ($restante !== null && $restante <= 0) {
            return 0;
        }

        app()->instance('current_tenant_id', $tenant->id);

        $encolados = 0;

        // La misma condición que la guarda de idempotencia del Job: sin
        // departamento **y** sin puesto del catálogo. Con cualquiera de los dos
        // ya sabemos dónde vota.
        //
        // El tope se aplica cortando el recorrido y no con un `limit()`: el
        // `chunkById` pagina por su cuenta y pisaría cualquier límite puesto en
        // la consulta, encolando la campaña entera.
        Voter::query()
            ->whereNull('departamento_votacion')
            ->whereNull('voting_place_id')
            ->whereNotNull('cedula')
            ->where('cedula', '!=', '')
            ->select(['id', 'tenant_id', 'cedula', 'departamento_votacion', 'voting_place_id'])
            ->chunkById(500, function ($votantes) use (&$encolados, $restante) {
                foreach ($votantes as $votante) {
                    if ($restante !== null && $encolados >= $restante) {
                        return false;
                    }

                    ConsultarPuestoVotacionJob::despacharSiFalta($votante);
                    $encolados++;
                }

                return true;
            });

        return $encolados;
    }
}
