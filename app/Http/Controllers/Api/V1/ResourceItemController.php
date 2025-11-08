<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreResourceItemRequest;
use App\Http\Requests\UpdateResourceItemRequest;
use App\Http\Resources\ResourceItemResource;
use App\Models\ResourceItem;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ResourceItemController extends Controller
{
    /**
     * Display a listing of the resource items.
     */
    public function index(Request $request): JsonResponse
    {
        $query = ResourceItem::query();

        // Filtro por categoría
        if ($request->has('filter.category')) {
            $query->byCategory($request->input('filter.category'));
        }

        // Filtro por activo
        if ($request->has('filter.is_active')) {
            $isActive = filter_var($request->input('filter.is_active'), FILTER_VALIDATE_BOOLEAN);
            if ($isActive) {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        }

        // Filtro por stock bajo
        if ($request->has('filter.low_stock') && filter_var($request->input('filter.low_stock'), FILTER_VALIDATE_BOOLEAN)) {
            $query->lowStock();
        }

        // Búsqueda por nombre
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'ILIKE', "%{$search}%");
        }

        // Ordenamiento
        $sortField = $request->input('sort', 'name');
        $sortDirection = str_starts_with($sortField, '-') ? 'desc' : 'asc';
        $sortField = ltrim($sortField, '-');
        $query->orderBy($sortField, $sortDirection);

        $perPage = $request->input('per_page', 15);
        $items = $query->paginate($perPage);

        return response()->json([
            'data' => ResourceItemResource::collection($items),
            'meta' => [
                'total' => $items->total(),
                'current_page' => $items->currentPage(),
                'per_page' => $items->perPage(),
                'last_page' => $items->lastPage(),
            ],
        ]);
    }

    /**
     * Store a newly created resource item.
     */
    public function store(StoreResourceItemRequest $request): JsonResponse
    {
        $data = $request->validated();
        
        // Asegurar valores por defecto
        $data['currency'] = $data['currency'] ?? 'COP';
        $data['is_active'] = $data['is_active'] ?? true;

        $item = ResourceItem::create($data);

        return response()->json([
            'data' => new ResourceItemResource($item),
            'message' => 'Recurso creado exitosamente',
        ], 201);
    }

    /**
     * Display the specified resource item.
     */
    public function show(ResourceItem $resourceItem): JsonResponse
    {
        return response()->json([
            'data' => new ResourceItemResource($resourceItem),
        ]);
    }

    /**
     * Update the specified resource item.
     */
    public function update(UpdateResourceItemRequest $request, ResourceItem $resourceItem): JsonResponse
    {
        $data = $request->validated();
        $resourceItem->update($data);

        return response()->json([
            'data' => new ResourceItemResource($resourceItem),
            'message' => 'Recurso actualizado exitosamente',
        ]);
    }

    /**
     * Remove the specified resource item (soft delete).
     */
    public function destroy(ResourceItem $resourceItem): JsonResponse
    {
        $resourceItem->delete();

        return response()->json([
            'message' => 'Recurso eliminado exitosamente',
        ]);
    }

    /**
     * Get items with low stock.
     */
    public function lowStock(): JsonResponse
    {
        $items = ResourceItem::lowStock()->active()->get();

        return response()->json([
            'data' => ResourceItemResource::collection($items),
            'count' => $items->count(),
        ]);
    }
}
