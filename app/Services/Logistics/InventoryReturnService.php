<?php

namespace App\Services\Logistics;

use App\Models\InventoryLoss;
use App\Models\ResourceAllocation;
use App\Models\ResourceAllocationItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierre de una asignación entregada (Spec 0057).
 *
 * Hasta ahora devolver era todo o nada: se reintegraba el 100 % aunque de la
 * vereda hubieran vuelto ocho sillas de diez, y las dos que faltaban
 * desaparecían del sistema sin dejar rastro. El estado por ítem parecía cubrirlo
 * (`lost`, `damaged`) pero solo escribía una etiqueta.
 *
 * Aquí el cierre declara, por ítem, cuánto volvió y cuánto no. Lo devuelto
 * vuelve al stock; lo perdido o dañado **no vuelve** —es merma— y queda
 * registrado con su valor y su responsable, que es lo que permite después decir
 * quién debe qué.
 */
class InventoryReturnService
{
    /**
     * @param  array<int, array<string, mixed>>  $items  desglose por ítem
     */
    public function close(ResourceAllocation $allocation, array $items, ?int $responsableId = null): ResourceAllocation
    {
        if ($allocation->status !== 'delivered') {
            $this->rechazar('Solo se cierra una asignación entregada.');
        }

        $declarados = $this->indexarPorItem($allocation, $items);

        return DB::transaction(fn () => $this->aplicar($allocation, $declarados, $responsableId));
    }

    /**
     * Valida el desglose contra los ítems de la asignación antes de tocar nada:
     * si un solo ítem no cuadra, no se mueve ninguno.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{item: ResourceAllocationItem, devuelto: float, perdido: float, danado: float, notas: ?string}>
     */
    private function indexarPorItem(ResourceAllocation $allocation, array $items): array
    {
        $delAsignacion = $allocation->items()->with('resourceItem')->get()->keyBy('id');
        $declarados = [];

        foreach ($items as $fila) {
            $id = (int) ($fila['id'] ?? 0);
            $item = $delAsignacion->get($id);

            if (! $item) {
                $this->rechazar("El ítem {$id} no pertenece a esta asignación.");
            }

            if ($item->closed_at !== null) {
                $this->rechazar("El ítem {$id} ya se cerró.");
            }

            $devuelto = (float) ($fila['quantity_returned'] ?? 0);
            $perdido = (float) ($fila['quantity_lost'] ?? 0);
            $danado = (float) ($fila['quantity_damaged'] ?? 0);
            $entregado = (float) $item->quantity;

            if ($devuelto + $perdido + $danado > $entregado) {
                $this->rechazar(
                    "Lo devuelto, perdido y dañado no puede superar lo entregado ({$this->numero($entregado)})."
                );
            }

            $declarados[$id] = [
                'item' => $item,
                'devuelto' => $devuelto,
                'perdido' => $perdido,
                'danado' => $danado,
                'notas' => $fila['notes'] ?? null,
            ];
        }

        return $declarados;
    }

    /**
     * @param  array<int, array<string, mixed>>  $declarados
     */
    private function aplicar(ResourceAllocation $allocation, array $declarados, ?int $responsableId): ResourceAllocation
    {
        $responsable = $responsableId
            ?? $allocation->assigned_to_user_id
            ?? $allocation->leader_user_id;

        foreach ($declarados as $declarado) {
            /** @var ResourceAllocationItem $item */
            $item = $declarado['item'];
            $recurso = $item->resourceItem;

            // Solo lo devuelto vuelve al almacén.
            if ($declarado['devuelto'] > 0) {
                $recurso?->increaseStock((int) $declarado['devuelto']);
            }

            foreach ([InventoryLoss::PERDIDO => $declarado['perdido'], InventoryLoss::DANADO => $declarado['danado']] as $razon => $cantidad) {
                if ($cantidad <= 0) {
                    continue;
                }

                // Fotografía del costo: lo perdido vale lo que valía hoy, no lo
                // que valga el catálogo cuando se lea el informe.
                $costoUnitario = (float) ($item->unit_cost ?? $recurso?->unit_cost ?? 0);

                InventoryLoss::create([
                    'tenant_id' => $allocation->tenant_id,
                    'resource_item_id' => $item->resource_item_id,
                    'resource_allocation_item_id' => $item->id,
                    'resource_allocation_id' => $allocation->id,
                    'meeting_id' => $allocation->meeting_id,
                    'responsible_user_id' => $responsable,
                    'quantity' => $cantidad,
                    'unit_cost' => $costoUnitario,
                    'value' => $cantidad * $costoUnitario,
                    'reason' => $razon,
                    'notes' => $declarado['notas'],
                ]);
            }

            $item->update([
                'quantity_returned' => $declarado['devuelto'],
                'quantity_lost' => $declarado['perdido'],
                'quantity_damaged' => $declarado['danado'],
                'closed_at' => now(),
                'returned_at' => now(),
                'returned_to_user_id' => auth()->id(),
                'status' => $this->estadoDelItem($declarado),
                'notes' => $declarado['notas'] ?? $item->notes,
            ]);
        }

        // La asignación se cierra cuando ya no queda ítem sin declarar.
        if (! $allocation->items()->whereNull('closed_at')->exists()) {
            $allocation->update(['status' => 'returned']);
        }

        return $allocation->fresh(['items.resourceItem']);
    }

    /**
     * El `status` del ítem sigue existiendo por compatibilidad con lo que ya
     * consume el panel; el desglose es la verdad, y `return_state` lo deriva.
     *
     * @param  array<string, mixed>  $declarado
     */
    private function estadoDelItem(array $declarado): string
    {
        $entregado = (float) $declarado['item']->quantity;

        if ($declarado['perdido'] >= $entregado) {
            return 'lost';
        }

        if ($declarado['danado'] >= $entregado) {
            return 'damaged';
        }

        return 'returned';
    }

    private function numero(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, '.', ''), '0'), '.');
    }

    private function rechazar(string $mensaje): never
    {
        throw ValidationException::withMessages(['items' => $mensaje])->status(422);
    }
}
