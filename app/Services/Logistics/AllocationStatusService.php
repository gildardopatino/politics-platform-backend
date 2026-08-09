<?php

namespace App\Services\Logistics;

use App\Models\ResourceAllocation;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de una asignación de recursos (Spec 0057, fase 0).
 *
 * La 0056 dejó por escrito que este ciclo existía en el controlador pero no era
 * alcanzable: `status` no estaba en las reglas del FormRequest, así que
 * `validated()` lo descartaba y la asignación se quedaba en `pending` para
 * siempre. Al repararlo, la máquina de estados se saca aquí por dos razones:
 * el controlador no es sitio para reglas de inventario, y la fase 1 (devolución
 * parcial y merma) necesita engancharse justo en esta transición.
 *
 * Antes esto solo corría `if ($allocation->items()->exists())`, así que una
 * entrega de dinero —que no tiene ítems— podía saltar a cualquier estado sin
 * validación. Ahora las guardas valen para todas; los ítems solo deciden si
 * además hay stock que mover.
 */
class AllocationStatusService
{
    /**
     * Qué se puede hacer desde cada estado. Repetir el estado actual no es una
     * transición: es un `PUT` que trae el mismo dato, y no debe mover stock dos
     * veces.
     */
    private const TRANSICIONES = [
        'pending' => ['delivered', 'cancelled'],
        'delivered' => ['returned'],
        'returned' => [],
        'cancelled' => [],
    ];

    /**
     * Aplica el cambio de estado y mueve el inventario que corresponda.
     *
     * Se espera dentro de una transacción del llamador (el controlador ya la
     * abre) para que el stock y el estado caigan juntos o no caigan.
     */
    public function cambiar(ResourceAllocation $allocation, string $nuevo): void
    {
        $actual = $allocation->status;

        if ($actual === $nuevo) {
            return;
        }

        $permitidas = self::TRANSICIONES[$actual] ?? [];

        if (! in_array($nuevo, $permitidas, true)) {
            throw ValidationException::withMessages([
                'status' => "Cambio de estado no permitido: {$actual} -> {$nuevo}",
            ])->status(422);
        }

        match ($nuevo) {
            'delivered' => $this->entregar($allocation),
            'returned' => $this->devolver($allocation),
            'cancelled' => $this->cancelar($allocation),
            default => null,
        };

        $allocation->status = $nuevo;
    }

    /** Las transiciones que salen del estado dado, para el mensaje de error. */
    public static function permitidasDesde(string $estado): array
    {
        return array_map(
            fn (string $destino) => "{$estado} -> {$destino}",
            self::TRANSICIONES[$estado] ?? []
        );
    }

    /**
     * Entregar: lo reservado sale del almacén. Se libera la reserva y se
     * descuenta del stock; si el stock no alcanza, no se entrega nada.
     */
    private function entregar(ResourceAllocation $allocation): void
    {
        foreach ($allocation->items()->with('resourceItem')->get() as $item) {
            $recurso = $item->resourceItem;

            if (! $recurso) {
                continue;
            }

            $recurso->releaseReservedStock((int) $item->quantity);

            if (! $recurso->decreaseStock((int) $item->quantity)) {
                throw ValidationException::withMessages([
                    'status' => "No hay suficiente stock para descontar '{$recurso->name}'",
                ])->status(422);
            }

            $item->update(['status' => 'delivered']);
        }
    }

    /**
     * Devolver: vuelve todo lo entregado. La devolución parcial —y la merma de
     * lo que no vuelve— es la fase 1; aquí sigue siendo todo o nada.
     */
    private function devolver(ResourceAllocation $allocation): void
    {
        foreach ($allocation->items()->with('resourceItem')->get() as $item) {
            $item->resourceItem?->increaseStock((int) $item->quantity);
            $item->update(['status' => 'returned']);
        }
    }

    /** Cancelar antes de entregar: la reserva se suelta y nada salió. */
    private function cancelar(ResourceAllocation $allocation): void
    {
        foreach ($allocation->items()->with('resourceItem')->get() as $item) {
            $item->resourceItem?->releaseReservedStock((int) $item->quantity);
            $item->update(['status' => 'cancelled']);
        }
    }
}
