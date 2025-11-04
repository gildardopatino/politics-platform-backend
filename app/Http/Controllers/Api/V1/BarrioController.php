<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Barrio\StoreBarrioRequest;
use App\Http\Requests\Api\V1\Barrio\UpdateBarrioRequest;
use App\Models\Barrio;
use Illuminate\Http\JsonResponse;

class BarrioController extends Controller
{
    public function index(): JsonResponse
    {
        $barrios = Barrio::with(['municipality', 'commune'])->get();
        return response()->json(['data' => $barrios]);
    }

    public function store(StoreBarrioRequest $request): JsonResponse
    {
        $barrio = Barrio::create($request->validated());
        return response()->json(['data' => $barrio->load(['municipality', 'commune']), 'message' => 'Barrio created successfully'], 201);
    }

    public function show(Barrio $barrio): JsonResponse
    {
        return response()->json(['data' => $barrio->load(['municipality', 'commune'])]);
    }

    public function update(UpdateBarrioRequest $request, Barrio $barrio): JsonResponse
    {
        $barrio->update($request->validated());
        return response()->json(['data' => $barrio->load(['municipality', 'commune']), 'message' => 'Barrio updated successfully']);
    }

    public function destroy(Barrio $barrio): JsonResponse
    {
        $barrio->delete();
        return response()->json(['message' => 'Barrio deleted successfully']);
    }

    /**
     * Search barrios by name
     * Returns barrio with its comuna information
     */
    public function search(): JsonResponse
    {
        request()->validate([
            'search' => 'required|string|min:2',
            'limit' => 'nullable|integer|min:1|max:50'
        ]);

        $search = request()->input('search');
        $limit = request()->input('limit', 20);

        $barrios = Barrio::with(['commune:id,nombre'])
            ->where('nombre', 'ilike', "%{$search}%")
            ->orderBy('nombre')
            ->limit($limit)
            ->get()
            ->map(function ($barrio) {
                return [
                    'id' => $barrio->id,
                    'nombre' => $barrio->nombre,
                    'comuna' => [
                        'id' => $barrio->commune->id ?? null,
                        'nombre' => $barrio->commune->nombre ?? null,
                    ],
                    'display_name' => $barrio->nombre . ' - ' . ($barrio->commune->nombre ?? 'Sin comuna'),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $barrios,
            'meta' => [
                'total' => $barrios->count(),
                'search' => $search,
            ]
        ]);
    }
}
