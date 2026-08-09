<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PettyCashAdvance;
use App\Models\PettyCashFund;
use App\Services\Logistics\PettyCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Anticipos de caja menor (Spec 0057).
 *
 * Entregar, legalizar y castigar. El castigo tiene su propio endpoint porque es
 * el caso real más frecuente —se entregó, no volvió y no hay recibo— y hacerlo
 * fácil es lo que consigue que se registre en vez de quedar en el aire.
 */
class PettyCashAdvanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PettyCashAdvance::query()->with(['user', 'meeting', 'fund', 'expenseLines']);

        foreach (['status', 'user_id', 'meeting_id', 'petty_cash_fund_id'] as $filtro) {
            if ($request->filled("filter.{$filtro}")) {
                $query->where($filtro, $request->input("filter.{$filtro}"));
            }
        }

        $resumen = (clone $query)->selectRaw(
            'COALESCE(SUM(amount), 0) as entregado, COALESCE(SUM(amount_spent), 0) as gastado, '.
            'COALESCE(SUM(amount_returned), 0) as devuelto, COALESCE(SUM(amount_charged_off), 0) as castigado'
        )->first();

        $anticipos = $query->latest()->paginate((int) $request->input('per_page', 15));

        return response()->json([
            'data' => $anticipos->items(),
            'meta' => [
                'total' => $anticipos->total(),
                'current_page' => $anticipos->currentPage(),
                'last_page' => $anticipos->lastPage(),
                'per_page' => $anticipos->perPage(),
            ],
            'summary' => [
                'total_advanced' => (float) $resumen->entregado,
                'total_spent' => (float) $resumen->gastado,
                'total_returned' => (float) $resumen->devuelto,
                'total_charged_off' => (float) $resumen->castigado,
                'total_pending' => (float) $resumen->entregado
                    - (float) $resumen->gastado
                    - (float) $resumen->devuelto
                    - (float) $resumen->castigado,
            ],
        ]);
    }

    public function store(Request $request, PettyCashService $caja): JsonResponse
    {
        $validated = $request->validate([
            'petty_cash_fund_id' => 'required|integer',
            'user_id' => 'required|exists:users,id',
            'meeting_id' => 'nullable|exists:meetings,id',
            'amount' => 'required|numeric',
            'purpose' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        // Por el TenantScope: un fondo de otra campaña no se encuentra.
        $fondo = PettyCashFund::findOrFail($validated['petty_cash_fund_id']);

        $anticipo = $caja->advance($fondo, $validated);

        return response()->json([
            'data' => $anticipo,
            'message' => 'Anticipo entregado exitosamente',
        ], 201);
    }

    public function show(PettyCashAdvance $pettyCashAdvance): JsonResponse
    {
        return response()->json([
            'data' => $pettyCashAdvance->load(['user', 'meeting', 'fund', 'expenseLines']),
        ]);
    }

    /** Cierra el anticipo explicando en qué se fue el dinero. */
    public function settle(Request $request, PettyCashAdvance $pettyCashAdvance, PettyCashService $caja): JsonResponse
    {
        $validated = $request->validate([
            'expense_lines' => 'present|array',
            'expense_lines.*.description' => 'required|string|max:255',
            'expense_lines.*.amount' => 'required|numeric|min:0',
            'expense_lines.*.has_receipt' => 'nullable|boolean',
            'expense_lines.*.receipt_ref' => 'nullable|string|max:255',
            'amount_returned' => 'nullable|numeric|min:0',
            'amount_charged_off' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $anticipo = $caja->settle(
            $pettyCashAdvance,
            $validated['expense_lines'],
            (float) ($validated['amount_returned'] ?? 0),
            (float) ($validated['amount_charged_off'] ?? 0),
            $validated['notes'] ?? null
        );

        return response()->json([
            'data' => $anticipo,
            'message' => 'Anticipo legalizado exitosamente',
        ]);
    }

    /** Ni se comprobó ni volvió: se acepta como gasto, pero queda registrado. */
    public function chargeOff(Request $request, PettyCashAdvance $pettyCashAdvance, PettyCashService $caja): JsonResponse
    {
        $validated = $request->validate(['notes' => 'nullable|string']);

        $anticipo = $caja->chargeOff($pettyCashAdvance, $validated['notes'] ?? null);

        return response()->json([
            'data' => $anticipo,
            'message' => 'Anticipo castigado exitosamente',
        ]);
    }
}
