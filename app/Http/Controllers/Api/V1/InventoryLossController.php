<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InventoryLoss;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consulta de mermas (Spec 0057).
 *
 * Responde a la pregunta que antes no se podía hacer: qué se perdió, cuánto
 * valía y quién lo tenía. Los filtros son los tres ejes con los que se lee —ítem,
 * responsable y reunión— porque son los que alimentan el informe de la 0036.
 */
class InventoryLossController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = InventoryLoss::query()->with(['resourceItem', 'responsible', 'meeting']);

        foreach (['resource_item_id', 'responsible_user_id', 'meeting_id', 'reason'] as $filtro) {
            if ($request->filled("filter.{$filtro}")) {
                $query->where($filtro, $request->input("filter.{$filtro}"));
            }
        }

        if ($request->filled('filter.from')) {
            $query->whereDate('created_at', '>=', $request->input('filter.from'));
        }

        if ($request->filled('filter.to')) {
            $query->whereDate('created_at', '<=', $request->input('filter.to'));
        }

        // El resumen se calcula sobre el mismo filtro, no sobre la página: un
        // total que solo sumara la página visible engañaría.
        $resumen = (clone $query)->selectRaw('COUNT(*) as total, COALESCE(SUM(value), 0) as total_value, COALESCE(SUM(quantity), 0) as total_quantity')->first();

        $mermas = $query->latest()->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'data' => $mermas->items(),
            'meta' => [
                'total' => $mermas->total(),
                'current_page' => $mermas->currentPage(),
                'last_page' => $mermas->lastPage(),
                'per_page' => $mermas->perPage(),
            ],
            'summary' => [
                'total_losses' => (int) $resumen->total,
                'total_quantity' => (float) $resumen->total_quantity,
                'total_value' => (float) $resumen->total_value,
            ],
        ]);
    }
}
