<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ResourceAllocation\StoreResourceAllocationRequest;
use App\Http\Requests\Api\V1\ResourceAllocation\UpdateResourceAllocationRequest;
use App\Http\Resources\Api\V1\ResourceAllocationResource;
use App\Models\Meeting;
use App\Models\ResourceAllocation;
use App\Models\ResourceAllocationItem;
use App\Models\ResourceItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Spatie\QueryBuilder\QueryBuilder;

class ResourceAllocationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $query = ResourceAllocation::query();
        
        // Aplicar includes si se solicitan
        $includes = request()->input('include', '');
        if ($includes) {
            $allowedIncludes = ['meeting', 'assignedBy', 'leader', 'assignedTo', 'items', 'items.resourceItem'];
            $requestedIncludes = explode(',', $includes);
            $validIncludes = array_intersect($requestedIncludes, $allowedIncludes);
            if (!empty($validIncludes)) {
                $query->with($validIncludes);
            }
        }
        
        // Aplicar filtros si se envían
        if (request()->has('filter')) {
            $filters = request()->input('filter');
            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }
            if (isset($filters['meeting_id'])) {
                $query->where('meeting_id', $filters['meeting_id']);
            }
            if (isset($filters['leader_user_id'])) {
                $query->where('leader_user_id', $filters['leader_user_id']);
            }
            if (isset($filters['status'])) {
                $query->where('status', $filters['status']);
            }
        }
        
        // Aplicar ordenamiento si se solicita
        $sort = request()->input('sort', '-created_at');
        $sortDirection = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $sortField = ltrim($sort, '-');
        $query->orderBy($sortField, $sortDirection);
        
        $perPage = request()->input('per_page', 15);
        $resources = $query->paginate($perPage);

        return response()->json([
            'data' => ResourceAllocationResource::collection($resources->items()),
            'meta' => [
                'total' => $resources->total(),
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
                'per_page' => $resources->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreResourceAllocationRequest $request): JsonResponse
    {
        DB::beginTransaction();
        try {
            $validated = $request->validated();
            
            // Si hay items, validar stock disponible ANTES de crear la asignación
            if (isset($validated['items']) && is_array($validated['items'])) {
                foreach ($validated['items'] as $itemData) {
                    $resourceItem = ResourceItem::find($itemData['resource_item_id']);
                    
                    if (!$resourceItem) {
                        DB::rollBack();
                        return response()->json([
                            'message' => 'Recurso no encontrado',
                            'resource_item_id' => $itemData['resource_item_id']
                        ], 404);
                    }
                    
                    if (!$resourceItem->hasAvailableStock($itemData['quantity'])) {
                        DB::rollBack();
                        return response()->json([
                            'message' => "Stock insuficiente para '{$resourceItem->name}'",
                            'resource' => $resourceItem->name,
                            'requested' => $itemData['quantity'],
                            'available' => $resourceItem->available_quantity,
                            'in_stock' => $resourceItem->stock_quantity,
                            'reserved' => $resourceItem->reserved_quantity
                        ], 422);
                    }
                }
            }
            
            // Preparar datos para la asignación
            $allocationData = [
                'tenant_id' => app('tenant')->id,
                'assigned_by_user_id' => auth('api')->id(),
                'leader_user_id' => $validated['leader_user_id'],
                'assigned_to_user_id' => $validated['assigned_to_user_id'] ?? $validated['leader_user_id'],
                'meeting_id' => $validated['meeting_id'] ?? null,
                'status' => 'pending',
            ];

            // Campos nuevos (sistema mejorado)
            if (isset($validated['title'])) {
                $allocationData['title'] = $validated['title'];
            }
            if (isset($validated['allocation_date'])) {
                $allocationData['allocation_date'] = $validated['allocation_date'];
            }
            if (isset($validated['notes'])) {
                $allocationData['notes'] = $validated['notes'];
            }

            // Campos legacy (compatibilidad)
            if (isset($validated['type'])) {
                $allocationData['type'] = $validated['type'];
            }
            if (isset($validated['descripcion'])) {
                $allocationData['descripcion'] = $validated['descripcion'];
            }
            if (isset($validated['amount'])) {
                $allocationData['amount'] = $validated['amount'];
            }
            if (isset($validated['fecha_asignacion'])) {
                $allocationData['fecha_asignacion'] = $validated['fecha_asignacion'];
            }
            if (isset($validated['details'])) {
                $allocationData['details'] = $validated['details'];
            }

            $resource = ResourceAllocation::create($allocationData);

            // Si hay items, crearlos y RESERVAR el stock
            if (isset($validated['items']) && is_array($validated['items'])) {
                $totalCost = 0;
                
                foreach ($validated['items'] as $itemData) {
                    $resourceItem = ResourceItem::find($itemData['resource_item_id']);
                    
                    // Reservar el stock
                    $resourceItem->reserveStock($itemData['quantity']);
                    
                    $allocationItem = ResourceAllocationItem::create([
                        'resource_allocation_id' => $resource->id,
                        'resource_item_id' => $itemData['resource_item_id'],
                        'quantity' => $itemData['quantity'],
                        'unit_cost' => $resourceItem->unit_cost,
                        'notes' => $itemData['notes'] ?? null,
                        'metadata' => $itemData['metadata'] ?? null,
                        'status' => 'pending',
                    ]);
                    
                    $totalCost += $allocationItem->subtotal;
                }
                
                // Actualizar total_cost
                $resource->update(['total_cost' => $totalCost]);
            }

            DB::commit();

            return response()->json([
                'data' => new ResourceAllocationResource($resource->load(['meeting', 'assignedBy', 'leader', 'items.resourceItem'])),
                'message' => 'Asignación de recursos creada exitosamente'
            ], 201);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al crear la asignación de recursos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(ResourceAllocation $resourceAllocation): JsonResponse
    {
        // Cargar relaciones solicitadas
        $includes = request()->input('include', '');
        $allowedIncludes = ['meeting', 'assignedBy', 'leader', 'assignedTo', 'items', 'items.resourceItem'];
        
        if ($includes) {
            $requestedIncludes = explode(',', $includes);
            $validIncludes = array_intersect($requestedIncludes, $allowedIncludes);
            if (!empty($validIncludes)) {
                $resourceAllocation->load($validIncludes);
            }
        }

        return response()->json([
            'data' => new ResourceAllocationResource($resourceAllocation)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateResourceAllocationRequest $request, ResourceAllocation $resourceAllocation): JsonResponse
    {
        DB::beginTransaction();
        try {
            $validated = $request->validated();
            $oldStatus = $resourceAllocation->status;
            $newStatus = $validated['status'] ?? $oldStatus;

            // Si cambia el estado, gestionar el inventario
            if ($oldStatus !== $newStatus && $resourceAllocation->items()->exists()) {
                
                // De pending a delivered: Descontar del stock y liberar reserva
                if ($oldStatus === 'pending' && $newStatus === 'delivered') {
                    foreach ($resourceAllocation->items as $item) {
                        $resourceItem = $item->resourceItem;
                        
                        // Liberar la reserva
                        $resourceItem->releaseReservedStock($item->quantity);
                        
                        // Descontar del stock
                        if (!$resourceItem->decreaseStock($item->quantity)) {
                            DB::rollBack();
                            return response()->json([
                                'message' => "No hay suficiente stock para descontar '{$resourceItem->name}'",
                                'resource' => $resourceItem->name,
                                'needed' => $item->quantity,
                                'available' => $resourceItem->stock_quantity
                            ], 422);
                        }
                        
                        // Actualizar estado del item
                        $item->update(['status' => 'delivered']);
                    }
                }
                
                // De delivered a returned: Devolver al stock
                elseif ($oldStatus === 'delivered' && $newStatus === 'returned') {
                    foreach ($resourceAllocation->items as $item) {
                        $resourceItem = $item->resourceItem;
                        
                        // Devolver al stock
                        $resourceItem->increaseStock($item->quantity);
                        
                        // Actualizar estado del item
                        $item->update(['status' => 'returned']);
                    }
                }
                
                // De pending a cancelled: Liberar reserva
                elseif ($oldStatus === 'pending' && $newStatus === 'cancelled') {
                    foreach ($resourceAllocation->items as $item) {
                        $resourceItem = $item->resourceItem;
                        
                        // Liberar la reserva
                        $resourceItem->releaseReservedStock($item->quantity);
                        
                        // Actualizar estado del item
                        $item->update(['status' => 'cancelled']);
                    }
                }
                
                // Otros cambios no permitidos
                elseif ($oldStatus !== $newStatus) {
                    DB::rollBack();
                    return response()->json([
                        'message' => "Cambio de estado no permitido: {$oldStatus} -> {$newStatus}",
                        'allowed_transitions' => [
                            'pending -> delivered',
                            'pending -> cancelled',
                            'delivered -> returned'
                        ]
                    ], 422);
                }
            }

            $resourceAllocation->update($validated);

            DB::commit();

            return response()->json([
                'data' => new ResourceAllocationResource($resourceAllocation->load(['meeting', 'assignedBy', 'leader', 'items.resourceItem'])),
                'message' => 'Resource allocation updated successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al actualizar la asignación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ResourceAllocation $resourceAllocation): JsonResponse
    {
        DB::beginTransaction();
        try {
            // Si está en pending, liberar las reservas
            if ($resourceAllocation->status === 'pending' && $resourceAllocation->items()->exists()) {
                foreach ($resourceAllocation->items as $item) {
                    $resourceItem = $item->resourceItem;
                    $resourceItem->releaseReservedStock($item->quantity);
                }
            }

            $resourceAllocation->delete();

            DB::commit();

            return response()->json([
                'message' => 'Resource allocation deleted successfully'
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al eliminar la asignación',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get resources by meeting
     */
    public function byMeeting(Meeting $meeting): JsonResponse
    {
        $resources = $meeting->resourceAllocations()
            ->with(['assignedBy', 'leader', 'items.resourceItem'])
            ->get();

        // Calcular totales del sistema legacy
        $totalCash = $resources->where('type', 'cash')->sum('amount');
        $totalMaterial = $resources->where('type', 'material')->sum('amount');
        $totalService = $resources->where('type', 'service')->sum('amount');
        
        // Calcular total del nuevo sistema (items)
        $totalFromItems = $resources->sum('total_cost');

        return response()->json([
            'data' => ResourceAllocationResource::collection($resources),
            'summary' => [
                'total_cash' => $totalCash,
                'total_material' => $totalMaterial,
                'total_service' => $totalService,
                'total_cost' => $totalFromItems,
                'grand_total' => $totalCash + $totalMaterial + $totalService + $totalFromItems,
            ]
        ]);
    }

    /**
     * Get resources by leader
     */
    public function byLeader(User $user): JsonResponse
    {
        $resources = ResourceAllocation::where('leader_user_id', $user->id)
            ->with(['meeting', 'assignedBy', 'items.resourceItem'])
            ->paginate(request('per_page', 15));

        // Calcular totales del sistema legacy
        $allResources = ResourceAllocation::where('leader_user_id', $user->id)->get();
        $totalCash = $allResources->where('type', 'cash')->sum('amount');
        $totalMaterial = $allResources->where('type', 'material')->sum('amount');
        $totalService = $allResources->where('type', 'service')->sum('amount');
        $totalFromItems = $allResources->sum('total_cost');

        return response()->json([
            'data' => ResourceAllocationResource::collection($resources->items()),
            'meta' => [
                'total' => $resources->total(),
                'current_page' => $resources->currentPage(),
                'last_page' => $resources->lastPage(),
            ],
            'summary' => [
                'total_cash' => $totalCash,
                'total_material' => $totalMaterial,
                'total_service' => $totalService,
                'total_cost' => $totalFromItems,
                'grand_total' => $totalCash + $totalMaterial + $totalService + $totalFromItems,
            ]
        ]);
    }
}
