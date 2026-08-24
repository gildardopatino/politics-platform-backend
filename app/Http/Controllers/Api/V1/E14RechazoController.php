<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\E14ActaRechazadaResource;
use App\Models\E14ActaRechazada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Las actas que se rechazaron por no ser de esta elección (Spec 0093 · RF-B6).
 *
 * Solo lectura, y a propósito: el rechazo lo decide el ingest cuando el lector
 * dice qué elección venía impresa en el papel. Esto es la constancia que hace
 * que el borrado se pueda explicar —«de los 80 PDFs, estos 4 eran de
 * gobernación»— en vez de que cuatro actas desaparezcan sin más.
 *
 * Va acotado por `TenantScope`, como todo el E-14: los rechazos de una campaña
 * son suyos (Art. III).
 */
class E14RechazoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rechazos = E14ActaRechazada::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate((int) $request->input('per_page', 50));

        return response()->json([
            'data' => E14ActaRechazadaResource::collection($rechazos->items()),
            'meta' => [
                'total' => $rechazos->total(),
                'current_page' => $rechazos->currentPage(),
                'last_page' => $rechazos->lastPage(),
                'per_page' => $rechazos->perPage(),
            ],
        ]);
    }
}
