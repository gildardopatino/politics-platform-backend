<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ResourceAllocationItemResource;
use App\Models\ResourceAllocationItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourceAllocationItemController extends Controller
{
    /**
     * Update status of an allocation item (delivery/return tracking)
     */
    public function updateStatus(Request $request, ResourceAllocationItem $resourceAllocationItem): JsonResponse
    {
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
        $validated = $request->validate([
            'quantity' => 'nullable|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'metadata' => 'nullable|array',
        ]);

        $resourceAllocationItem->update($validated);

        // Recalcular el total de la asignación
        $allocation = $resourceAllocationItem->resourceAllocation;
        $allocation->update(['total_cost' => $allocation->items->sum('subtotal')]);

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
        $allocation = $resourceAllocationItem->resourceAllocation;
        $resourceAllocationItem->delete();

        // Recalcular el total de la asignación
        $allocation->update(['total_cost' => $allocation->items->sum('subtotal')]);

        return response()->json([
            'message' => 'Item eliminado exitosamente',
        ]);
    }
}
