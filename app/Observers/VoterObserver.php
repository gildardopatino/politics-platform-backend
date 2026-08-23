<?php

namespace App\Observers;

use App\Jobs\Registraduria\ConsultarPuestoVotacionJob;
use App\Models\Voter;

/**
 * Encola la consulta del puesto de votación al nacer el votante (Spec 0091).
 *
 * Es el **punto único** de disparo: los votantes nacen por tres caminos —el
 * check-in de reunión (Spec 0022), el alta manual del panel y los comandos de
 * sincronización— y repartir el `dispatch` por cada uno era garantizar que el
 * cuarto se olvidara. El observer los cubre todos.
 *
 * Dos guardas:
 *
 * - **Solo quien nace sin puesto.** Si la ubicación vino tecleada no hay nada
 *   que preguntar, y 2Captcha se cobra por consulta.
 * - **Solo con el servicio configurado.** Sin `services.registraduria.url` el
 *   flujo queda apagado en vez de llenar la cola de trabajos condenados a
 *   fallar; es el estado por defecto de una instalación sin el servicio Python
 *   levantado (y el de las pruebas).
 */
class VoterObserver
{
    public function created(Voter $voter): void
    {
        if (! $this->hayServicio() || ! $this->naceSinPuesto($voter) || $voter->tenant_id === null) {
            return;
        }

        // Ids, no el modelo: lo que se guarda en la cola no lleva PII (Art. VII).
        // Si la transacción que lo creó acaba revirtiéndose, el Job no encontrará
        // al votante y termina sin hacer nada.
        ConsultarPuestoVotacionJob::dispatch($voter->id, (int) $voter->tenant_id);
    }

    private function hayServicio(): bool
    {
        return filled(config('services.registraduria.url'));
    }

    private function naceSinPuesto(Voter $voter): bool
    {
        return blank($voter->departamento_votacion) && $voter->voting_place_id === null;
    }
}
