<?php

namespace App\Services\E14;

use App\Models\ElectoralEvent;
use Illuminate\Validation\ValidationException;

/**
 * A qué elección pertenece un acta (Specs 0061 y 0071).
 *
 * Si el cliente no la nombra se resuelve por tipo y se crea al vuelo. Exigir que
 * alguien dé de alta la elección antes de poder subir un acta sería un paso de
 * configuración que la noche del escrutinio nadie va a dar; el `firstOrCreate`
 * sobre la clave natural evita que se multipliquen.
 *
 * Vive aparte porque ahora hay dos puertas de entrada —la ingesta directa del
 * lector y la carga desde el panel— y la regla tiene que ser la misma en las dos.
 */
class EventoResolver
{
    /**
     * @param  array<string, mixed>  $datos
     */
    public function resolver(array $datos, int $tenantId): ElectoralEvent
    {
        if (! empty($datos['electoral_event_id'])) {
            // La búsqueda va con `TenantScope`: una elección de otro tenant
            // sencillamente no existe desde aquí.
            $evento = ElectoralEvent::find($datos['electoral_event_id']);

            if (! $evento) {
                throw ValidationException::withMessages([
                    'electoral_event_id' => 'La elección indicada no existe en esta campaña.',
                ]);
            }

            return $evento;
        }

        $tipo = $datos['tipo'];

        return ElectoralEvent::firstOrCreate(
            [
                'tenant_id' => $tenantId,
                'tipo' => $tipo,
                'nombre' => $datos['evento_nombre'] ?? $this->nombrePorDefecto($tipo),
            ],
            ['fecha' => $datos['evento_fecha'] ?? null],
        );
    }

    private function nombrePorDefecto(string $tipo): string
    {
        return match ($tipo) {
            'alcaldia' => 'Alcaldía',
            'gobernacion' => 'Gobernación',
            'concejo' => 'Concejo',
            'senado' => 'Senado',
            'asamblea_departamental' => 'Asamblea Departamental',
            default => ucfirst($tipo),
        };
    }
}
