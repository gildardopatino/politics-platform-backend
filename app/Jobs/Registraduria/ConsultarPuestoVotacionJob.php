<?php

namespace App\Jobs\Registraduria;

use App\Models\Voter;
use App\Services\Registraduria\Cedula;
use App\Services\Registraduria\RegistraduriaClient;
use App\Services\Registraduria\RegistraduriaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Consulta el puesto de votación de un votante y lo guarda (Spec 0091).
 *
 * Sustituye al *pull* de n8n (Spec 0030): en vez de que un tercero pregunte
 * quién falta y escriba por webhook, es la campaña la que sale a consultar —en
 * cola, porque la consulta tarda segundos y no puede colgar un check-in.
 *
 * Tres cosas lo gobiernan:
 *
 * 1. **Tenant explícito.** Un Job no trae petición, así que `EnsureTenant` no
 *    corrió y `TenantScope` no filtraría: el `tenant_id` viaja en el Job y se
 *    enlaza aquí (Constitución, Art. III). Sin eso el resolver usaría los alias
 *    de la campaña equivocada.
 * 2. **Guarda de idempotencia.** Si el votante ya tiene puesto, termina sin
 *    consultar: dos check-in casi simultáneos de la misma persona encolan dos
 *    Jobs y solo el primero gasta la consulta.
 * 3. **`no_encontrado` no es un fallo.** Esa cédula no está en el censo;
 *    insistir no la va a poner. Solo los fallos técnicos se reintentan.
 *
 * Se serializan **ids**, nunca la cédula: lo que quede en `jobs`/`failed_jobs`
 * no lleva PII (Art. VII).
 */
class ConsultarPuestoVotacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Holgado sobre el techo del cliente (300 s): el camino con 2Captcha del
     * servicio Python puede tardar.
     */
    public int $timeout = 360;

    public function __construct(
        public readonly int $voterId,
        public readonly int $tenantId,
    ) {}

    /**
     * Reintentos espaciados: si el servicio está reiniciándose, insistir cada
     * segundo no ayuda.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(RegistraduriaClient $cliente, RegistraduriaSyncService $sync): void
    {
        $enlaceAnterior = app()->bound('current_tenant_id') ? app('current_tenant_id') : null;
        app()->instance('current_tenant_id', $this->tenantId);

        try {
            $this->consultar($cliente, $sync);
        } finally {
            // Se deja como estaba: el worker atiende Jobs de varias campañas.
            app()->instance('current_tenant_id', $enlaceAnterior);
        }
    }

    private function consultar(RegistraduriaClient $cliente, RegistraduriaSyncService $sync): void
    {
        // Acotado por `TenantScope`: un id de otra campaña no aparece.
        $voter = Voter::find($this->voterId);

        if (! $voter) {
            Log::info('Consulta de Registraduría omitida: el votante ya no existe en la campaña', [
                'voter_id' => $this->voterId,
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        if ($this->yaTienePuesto($voter)) {
            return;
        }

        $cedula = Cedula::normalizar($voter->cedula);

        if ($cedula === '') {
            Log::warning('Consulta de Registraduría omitida: el votante no tiene cédula utilizable', [
                'voter_id' => $voter->id,
            ]);

            return;
        }

        $resultado = $cliente->consultar($cedula);

        if ($resultado->esNoEncontrado()) {
            Log::info('La Registraduría no tiene puesto para este votante', [
                'voter_id' => $voter->id,
                'via' => $resultado->via,
            ]);

            return;
        }

        if ($resultado->haFallado()) {
            // Se lanza a propósito: es lo que hace que la cola reintente con
            // `backoff()`. Agotados los intentos queda en `failed_jobs` y lo
            // recoge la próxima corrida de `voters:consultar-puestos`.
            throw new RuntimeException('No se pudo consultar la Registraduría: '.$resultado->motivo);
        }

        $fila = $resultado->primerRegistro();
        $datos = $fila === null ? [] : RegistraduriaSyncService::mapear($fila);

        if ($datos === []) {
            Log::warning('La Registraduría respondió sin un puesto utilizable', [
                'voter_id' => $voter->id,
                'via' => $resultado->via,
            ]);

            return;
        }

        $sync->aplicar($voter, $datos);

        Log::info('Puesto de votación resuelto desde Registraduría', [
            'voter_id' => $voter->id,
            'via' => $resultado->via,
        ]);
    }

    /**
     * Ya se sabe dónde vota: no hay nada que consultar.
     */
    private function yaTienePuesto(Voter $voter): bool
    {
        return filled($voter->departamento_votacion) || $voter->voting_place_id !== null;
    }
}
