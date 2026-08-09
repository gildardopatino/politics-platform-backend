<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PettyCashFund;
use App\Services\Logistics\PettyCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fondos de caja menor (Spec 0057).
 *
 * El saldo es de **solo lectura**: no se puede fijar al crear ni al editar. Se
 * mueve exclusivamente por reposiciones, anticipos y reintegros, que es lo que
 * garantiza que la cifra siempre tenga una historia detrás.
 */
class PettyCashFundController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PettyCashFund::query()->with('responsible');

        if ($request->filled('filter.is_active')) {
            $query->where('is_active', filter_var($request->input('filter.is_active'), FILTER_VALIDATE_BOOLEAN));
        }

        $fondos = $query->orderBy('name')->get();

        return response()->json([
            'data' => $fondos,
            'summary' => [
                'total_balance' => (float) $fondos->sum('balance'),
                'funds' => $fondos->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'responsible_user_id' => 'nullable|exists:users,id',
            'currency' => 'nullable|string|size:3',
            'is_active' => 'nullable|boolean',
            'initial_balance' => 'nullable|numeric|min:0',
        ]);

        $fondo = PettyCashFund::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'responsible_user_id' => $validated['responsible_user_id'] ?? null,
            'currency' => $validated['currency'] ?? 'COP',
            'is_active' => $validated['is_active'] ?? true,
        ]);

        // Un saldo inicial no se escribe: se repone, para que quede su movimiento.
        if (! empty($validated['initial_balance'])) {
            app(PettyCashService::class)->replenish($fondo, (float) $validated['initial_balance'], 'Saldo inicial');
        }

        return response()->json([
            'data' => $fondo->fresh('responsible'),
            'message' => 'Fondo creado exitosamente',
        ], 201);
    }

    public function show(PettyCashFund $pettyCashFund): JsonResponse
    {
        return response()->json([
            'data' => $pettyCashFund->load(['responsible', 'movements' => fn ($q) => $q->latest()->limit(50)]),
        ]);
    }

    public function update(Request $request, PettyCashFund $pettyCashFund): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'responsible_user_id' => 'nullable|exists:users,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $pettyCashFund->update($validated);

        return response()->json([
            'data' => $pettyCashFund->fresh('responsible'),
            'message' => 'Fondo actualizado exitosamente',
        ]);
    }

    /** Entra dinero al fondo. */
    public function replenish(Request $request, PettyCashFund $pettyCashFund, PettyCashService $caja): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric',
            'notes' => 'nullable|string',
        ]);

        $fondo = $caja->replenish($pettyCashFund, (float) $validated['amount'], $validated['notes'] ?? null);

        return response()->json([
            'data' => $fondo,
            'message' => 'Reposición registrada exitosamente',
        ]);
    }

    public function destroy(PettyCashFund $pettyCashFund): JsonResponse
    {
        if ($pettyCashFund->advances()->pending()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar un fondo con anticipos sin legalizar.',
            ], 422);
        }

        $pettyCashFund->delete();

        return response()->json(['message' => 'Fondo eliminado exitosamente']);
    }
}
