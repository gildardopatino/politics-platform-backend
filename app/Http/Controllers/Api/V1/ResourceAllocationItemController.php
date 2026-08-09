<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ResourceAllocationItemResource;
use App\Models\ResourceAllocation;
use App\Models\ResourceAllocationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourceAllocationItemController extends Controller
{
    /**
     * El ítem de una asignación no lleva `tenant_id` propio: cuelga de la
     * asignación (Spec 0056). Como estas tres rutas resuelven el modelo por
     * binding directo, sin esta guarda cualquiera con `edit_resources` podía
     * tocar los ítems de otra campaña con solo saber el id. La asignación sí usa
     * `HasTenant`, así que preguntar por ella a través del scope es lo que
     * decide si el ítem es visible.
     */
    private function asignacionDelTenant(ResourceAllocationItem $item): ResourceAllocation
    {
        $allocation = ResourceAllocation::find($item->resource_allocation_id);

        abort_if($allocation === null, 404);

        return $allocation;
    }

    /**
     * Update status of an allocation item (delivery/return tracking)
     */
    public function updateStatus(Request $request, ResourceAllocationItem $resourceAllocationItem): JsonResponse
    {
        $this->asignacionDelTenant($resourceAllocationItem);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['pending', 'delivered', 'returned', 'damaged', 'lost'])],
            'delivered_at' => 'nullable|date',
            'returned_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $updateData = ['status' => $validated['status']];

        // Si se marca como entregado
        if ($validated['status'] === 'delivered') {
            $updateData['delivered_at'] = $validated['delivered_at'] ?? now();
            $updateData['delivered_by_user_id'] = auth()->id();
        }

        // Si se marca como devuelto
        if (in_array($validated['status'], ['returned', 'damaged', 'lost'])) {
            $updateData['returned_at'] = $validated['returned_at'] ?? now();
            $updateData['returned_to_user_id'] = auth()->id();
        }

        if (isset($validated['notes'])) {
            $updateData['notes'] = $validated['notes'];
        }

        $resourceAllocationItem->update($updateData);

        return response()->json([
            'data' => new ResourceAllocationItemResource($resourceAllocationItem->load('resourceItem')),
            'message' => 'Estado del item actualizado exitosamente',
        ]);
    }

    /**
     * Update quantity or cost of an item
     */
    public function update(Request $request, ResourceAllocationItem $resourceAllocationItem): JsonResponse
    {
        $allocation = $this->asignacionDelTenant($resourceAllocationItem);

        $validated = $request->validate([
            'quantity' => 'nullable|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        // Cambiar la cantidad de un item pendiente cambia lo que hay apartado en
        // el almacen. Antes no se reajustaba nada: se podian pedir 40 con 10
        // reservadas, y sin comprobar que existieran (Spec 0056, H8). Solo
        // aplica mientras la asignacion siga pendiente; una vez entregada las
        // unidades ya salieron y no hay reserva que mover.
        if (isset($validated['quantity']) && $allocation->status === 'pending') {
            $recurso = $resourceAllocationItem->resourceItem;
            $anterior = (int) $resourceAllocationItem->quantity;
            $nueva = (int) $validated['quantity'];
            $diferencia = $nueva - $anterior;

            if ($recurso && $diferencia > 0 && ! $recurso->hasAvailableStock($diferencia)) {
                return response()->json([
                    'message' => "Stock insuficiente para '{$recurso->name}'",
                    'resource' => $recurso->name,
                    'requested' => $nueva,
                    'available' => $recurso->available_quantity + $anterior,
                    'in_stock' => $recurso->stock_quantity,
                    'reserved' => $recurso->reserved_quantity,
                ], 422);
            }

            if ($recurso && $diferencia > 0) {
                $recurso->reserveStock($diferencia);
            } elseif ($recurso && $diferencia < 0) {
                $recurso->releaseReservedStock(-$diferencia);
            }
        }

        $resourceAllocationItem->update($validated);

        // Recalcular el total de la asignación
        $allocation->update(['total_cost' => $allocation->items()->sum('subtotal')]);

        return response()->json([
            'data' => new ResourceAllocationItemResource($resourceAllocationItem->load('resourceItem')),
            'message' => 'Item actualizado exitosamente',
        ]);
    }

    /**
     * Delete an item from allocation
     */
    public function destroy(ResourceAllocationItem $resourceAllocationItem): JsonResponse
    {
        $allocation = $this->asignacionDelTenant($resourceAllocationItem);

        // Si la asignación sigue pendiente, esas unidades estaban RESERVADAS en
        // el catálogo. Borrar el ítem sin soltarlas las dejaba reservadas para
        // siempre, sin ninguna asignación que las reclamara: cada borrado hacía
        // el inventario disponible un poco más pequeño de lo que era (Spec 0056).
        // Es la misma regla que ya aplica al borrar la asignación entera.
        if ($allocation->status === 'pending' && $resourceAllocationItem->resourceItem) {
            $resourceAllocationItem->resourceItem->releaseReservedStock((int) $resourceAllocationItem->quantity);
        }

        $resourceAllocationItem->delete();

        // Recalcular el total de la asignación
        $allocation->update(['total_cost' => $allocation->items()->sum('subtotal')]);

        return response()->json([
            'message' => 'Item eliminado exitosamente',
        ]);
    }
}
