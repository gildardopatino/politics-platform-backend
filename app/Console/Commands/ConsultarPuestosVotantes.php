<?php

namespace App\Console\Commands;

use App\Jobs\Registraduria\ConsultarPuestoVotacionJob;
use App\Models\Tenant;
use App\Models\Voter;
use Illuminate\Console\Command;

/**
 * Encola la consulta del puesto de votación de quien todavía no lo tiene
 * (Spec 0091, Parte B).
 *
 * Reemplaza al `GET /webhook/political/registraduria/pendientes` de n8n: la
 * lista de pendientes ya no se le entrega a nadie, se recorre aquí y se encola
 * un Job por votante.
 *
 * Es **idempotente**: solo mira a quien no tiene puesto, así que una segunda
 * corrida no vuelve a encolar a los que se resolvieron. Y va **campaña por
 * campaña** enlazando `current_tenant_id`: en consola no hay petición, así que
 * sin ese enlace `TenantScope` no acotaría nada (Constitución, Art. III).
 *
 * `--limit` acota el gasto: el servicio cae a 2Captcha cuando el camino gratis
 * falla, y eso se cobra por consulta.
 */
class ConsultarPuestosVotantes extends Command
{
    protected $signature = 'voters:consultar-puestos
                            {--tenant= : Solo esta campaña (id del tenant)}
                            {--limit= : Máximo de consultas a encolar en total}';

    protected $description = 'Encola la consulta a Registraduría de los votantes que aún no tienen puesto de votación (Spec 0091)';

    public function handle(): int
    {
        if (blank(config('services.registraduria.url'))) {
            $this->error('El servicio de Registraduría no está configurado: define REGISTRADURIA_SERVICE_URL en el .env.');

            return self::FAILURE;
        }

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->orderBy('id')
            ->get(['id', 'nombre']);

        if ($tenants->isEmpty()) {
            $this->warn('No hay campañas que recorrer.');

            return self::SUCCESS;
        }

        $limite = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $enlaceAnterior = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;

        $filas = [];
        $total = 0;

        foreach ($tenants as $tenant) {
            $restante = $limite === null ? null : $limite - $total;

            if ($restante === 0) {
                break;
            }

            $encolados = $this->encolarTenant($tenant, $restante);
            $total += $encolados;

            if ($encolados > 0) {
                $filas[] = [$tenant->nombre, $encolados];
            }
        }

        // La consola no tiene petición que enlace el tenant: lo enlaza este
        // comando y lo deja como estaba.
        app()->instance('current_tenant_id', $enlaceAnterior);

        $this->newLine();

        if ($filas === []) {
            $this->info('No hay votantes sin puesto de votación: nada que consultar.');

            return self::SUCCESS;
        }

        $this->info('Consultas de Registraduría encoladas:');
        $this->table(['Campaña', 'Votantes'], $filas);
        $this->line("Total: {$total}");

        return self::SUCCESS;
    }

    /**
     * Una campaña, con su tenant enlazado.
     */
    private function encolarTenant(Tenant $tenant, ?int $restante): int
    {
        app()->instance('current_tenant_id', $tenant->id);

        $pendientes = Voter::query()
            ->whereNull('voting_place_id')
            ->where(fn ($q) => $q->whereNull('departamento_votacion')->orWhere('departamento_votacion', ''))
            ->orderBy('id')
            ->when($restante !== null, fn ($q) => $q->limit($restante))
            ->pluck('id');

        foreach ($pendientes as $voterId) {
            ConsultarPuestoVotacionJob::dispatch((int) $voterId, $tenant->id);
        }

        return $pendientes->count();
    }
}
