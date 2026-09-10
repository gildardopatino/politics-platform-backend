<?php

namespace App\Jobs\Registraduria;

use App\Models\Voter;
use App\Services\Registraduria\RegistraduriaClient;
use App\Services\Registraduria\RegistraduriaSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Resuelve el puesto de votación de un votante contra la Registraduría (Spec 0091).
 *
 * Va en cola y no en la petición porque la consulta tarda segundos —más si entra
 * 2Captcha— y el disparador natural es un check-in de reunión, que no puede
 * quedarse esperando a un scraper (Art. VI).
 *
 * Lleva **el id y el tenant, no el modelo**: `SerializesModels` recargaría al
 * votante en el worker, donde no hay petición que enlace `current_tenant_id`, y
 * `TenantScope` no filtraría. Aquí el tenant se enlaza a mano y el votante se
 * busca **dentro** de ese ámbito, así que un id de otra campaña simplemente no
 * aparece (Art. III).
 */
class ConsultarPuestoVotacionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly int $voterId,
        public readonly int $tenantId,
    ) {}

    /**
     * Un minuto, luego cinco. El servicio suele caerse por estar apagado o por
     * un reto que no pasó, y ninguna de las dos cosas se arregla insistiendo
     * rápido. Agotados los intentos, el votante queda para el próximo backfill.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * El único sitio que decide si hay que consultar por un votante.
     *
     * Lo llaman las dos vías por las que nace un votante —el flujo
     * asistente→votante de la Spec 0022 y el alta manual— y el comando de
     * backfill, para que la regla («solo si no tiene puesto, y solo si hay
     * servicio») viva en un sitio y no en tres copias.
     */
    public static function despacharSiFalta(Voter $voter): void
    {
        // Sin servicio configurado la integración está apagada: llenar la cola de
        // trabajos que solo pueden fallar no ayuda a nadie. Es también lo que
        // mantiene la suite de pruebas —que corre la cola en `sync`— fuera de la red.
        if (! app(RegistraduriaClient::class)->configurado()) {
            return;
        }

        if (! $voter->exists || blank($voter->tenant_id) || blank($voter->cedula)) {
            return;
        }

        if (self::tienePuesto($voter)) {
            return;
        }

        self::dispatch($voter->id, (int) $voter->tenant_id);
    }

    public function handle(RegistraduriaClient $cliente, RegistraduriaSyncService $sync): void
    {
        $habia = app()->bound('current_tenant_id');
        $anterior = $habia ? app('current_tenant_id') : null;

        app()->instance('current_tenant_id', $this->tenantId);

        try {
            $this->resolver($cliente, $sync);
        } finally {
            // El worker es un proceso largo: dejar el tenant puesto convertiría
            // el ámbito de este trabajo en el del siguiente.
            if ($habia) {
                app()->instance('current_tenant_id', $anterior);
            } else {
                app()->forgetInstance('current_tenant_id');
            }
        }
    }

    private function resolver(RegistraduriaClient $cliente, RegistraduriaSyncService $sync): void
    {
        // Acotado por `TenantScope`: un votante de otra campaña no existe aquí.
        $voter = Voter::find($this->voterId);

        if (! $voter) {
            return;
        }

        // Guarda de idempotencia: entre que se encoló y se ejecutó, el puesto
        // pudo llegar por otra vía (un acta, una edición, un Job gemelo de dos
        // check-in simultáneos). Consultar de nuevo es pagar 2Captcha por un dato
        // que ya tenemos.
        if (self::tienePuesto($voter)) {
            return;
        }

        if (blank($voter->cedula)) {
            Log::warning('Votante sin cédula: no hay nada que consultar', ['voter_id' => $voter->id]);

            return;
        }

        $resultado = $cliente->consultar($voter->cedula);

        if ($resultado->esNoEncontrado()) {
            // No es un fallo: la Registraduría respondió que no tiene esa cédula.
            // Reintentarlo es pagar por volver a oír lo mismo, así que el trabajo
            // termina bien y el votante solo se reintenta si alguien corre el
            // backfill.
            Log::info('La Registraduría no tiene puesto para este votante', [
                'voter_id' => $voter->id,
                'via' => $resultado->via,
            ]);

            return;
        }

        if ($resultado->esFallo()) {
            // Excepción y no `fail()`: es lo que hace que la cola reintente con
            // el backoff de arriba. El motivo es un código corto; la cédula no
            // entra en el mensaje (Art. VII).
            throw new RuntimeException(
                "No se pudo consultar el puesto del votante {$voter->id}: {$resultado->motivo}"
            );
        }

        $sync->aplicar($voter, $resultado->datos);
    }

    /**
     * Un votante «tiene puesto» si sabemos dónde vota por nombre **o** si ya
     * apunta a un renglón del catálogo. Con cualquiera de las dos, la consulta
     * no aportaría nada.
     */
    private static function tienePuesto(Voter $voter): bool
    {
        return filled($voter->departamento_votacion) || $voter->voting_place_id !== null;
    }
}
