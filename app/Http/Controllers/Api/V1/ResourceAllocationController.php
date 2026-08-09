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
use App\Services\Logistics\AllocationStatusService;
use App\Services\WhatsAppNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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
            if (! empty($validIncludes)) {
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
            ],
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreResourceAllocationRequest $request, WhatsAppNotificationService $whatsappService): JsonResponse
    {
        DB::beginTransaction();
        try {
            $validated = $request->validated();

            // Si hay items, validar stock disponible ANTES de crear la asignación
            if (isset($validated['items']) && is_array($validated['items'])) {
                foreach ($validated['items'] as $itemData) {
                    $resourceItem = ResourceItem::find($itemData['resource_item_id']);

                    if (! $resourceItem) {
                        DB::rollBack();

                        return response()->json([
                            'message' => 'Recurso no encontrado',
                            'resource_item_id' => $itemData['resource_item_id'],
                        ], 404);
                    }

                    if (! $resourceItem->hasAvailableStock($itemData['quantity'])) {
                        DB::rollBack();

                        return response()->json([
                            'message' => "Stock insuficiente para '{$resourceItem->name}'",
                            'resource' => $resourceItem->name,
                            'requested' => $itemData['quantity'],
                            'available' => $resourceItem->available_quantity,
                            'in_stock' => $resourceItem->stock_quantity,
                            'reserved' => $resourceItem->reserved_quantity,
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
            // El proposito del efectivo: el unico dato que explicaba en que se
            // iba el dinero y no tenia forma de entrar (Spec 0056, H5).
            if (isset($validated['cash_purpose'])) {
                $allocationData['cash_purpose'] = $validated['cash_purpose'];
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

            // Enviar notificación de WhatsApp al Responsable politico si la asignación está asociada a una reunión
            $whatsappSent = false;
            if ($resource->meeting_id) {
                $resource->load(['meeting.planner', 'items.resourceItem']);
                $meeting = $resource->meeting;

                if ($meeting && $meeting->planner && $meeting->planner->phone) {
                    $whatsappSent = $this->sendResourceAssignmentNotification($resource, $meeting, $whatsappService, auth('api')->user());
                }
            }

            DB::commit();

            return response()->json([
                'data' => new ResourceAllocationResource($resource->load(['meeting', 'assignedBy', 'leader', 'items.resourceItem'])),
                'message' => 'Asignación de recursos creada exitosamente',
                'whatsapp_notification_sent' => $whatsappSent,
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            // El detalle se registra pero no viaja al cliente: exponia la
            // consulta y el motor de base de datos (Spec 0056, H18).
            Log::error('Error al crear la asignacion de recursos', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al crear la asignación de recursos',
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
            if (! empty($validIncludes)) {
                $resourceAllocation->load($validIncludes);
            }
        }

        return response()->json([
            'data' => new ResourceAllocationResource($resourceAllocation),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(
        UpdateResourceAllocationRequest $request,
        ResourceAllocation $resourceAllocation,
        AllocationStatusService $estados
    ): JsonResponse {
        $estadoAnterior = $resourceAllocation->status;

        DB::beginTransaction();
        try {
            $validated = $request->validated();

            // El ciclo de vida vive en el servicio: valida la transicion y mueve
            // el inventario que corresponda (Spec 0057). Antes estaba aqui, y
            // encima solo se evaluaba si la asignacion tenia items, asi que una
            // entrega de dinero podia saltar a cualquier estado.
            if (array_key_exists('status', $validated)) {
                $estados->cambiar($resourceAllocation, $validated['status']);
                unset($validated['status']);
            }

            $resourceAllocation->update($validated);
            $resourceAllocation->save();

            DB::commit();

            return response()->json([
                'data' => new ResourceAllocationResource($resourceAllocation->load(['meeting', 'assignedBy', 'leader', 'items.resourceItem'])),
                'message' => 'Resource allocation updated successfully',
            ]);

        } catch (ValidationException $e) {
            DB::rollBack();

            return response()->json([
                'message' => $e->validator->errors()->first(),
                'allowed_transitions' => AllocationStatusService::permitidasDesde($estadoAnterior),
            ], 422);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al actualizar la asignacion de recursos', [
                'allocation_id' => $resourceAllocation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al actualizar la asignación',
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
                'message' => 'Resource allocation deleted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error al eliminar la asignacion de recursos', [
                'allocation_id' => $resourceAllocation->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error al eliminar la asignación',
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
            ],
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
            ],
        ]);
    }

    /**
     * Send WhatsApp notification to planner when resources are assigned
     */
    private function sendResourceAssignmentNotification(
        ResourceAllocation $allocation,
        Meeting $meeting,
        WhatsAppNotificationService $whatsappService,
        \App\Models\User $user
    ): bool {
        try {
            $meetingDate = \Carbon\Carbon::parse($meeting->datetime)->format('d/m/Y H:i');

            $message = "📦 *Recursos Asignados*\n\n";
            $message .= "*Reunión:* {$meeting->title}\n";
            $message .= "*Fecha:* {$meetingDate}\n";

            if ($allocation->title) {
                $message .= "*Asignación:* {$allocation->title}\n";
            }

            $message .= "\n*Recursos:*\n";
            foreach ($allocation->items as $item) {
                $message .= "• {$item->resourceItem->name} (x{$item->quantity})\n";
            }

            if ($allocation->total_cost > 0) {
                $message .= "\n*Costo Total:* $".number_format($allocation->total_cost, 0, ',', '.');
            }

            if ($allocation->allocation_date) {
                $deliveryDate = \Carbon\Carbon::parse($allocation->allocation_date)->format('d/m/Y');
                $message .= "\n*Fecha de entrega:* {$deliveryDate}";
            }

            $success = $whatsappService->sendMessage(
                $meeting->planner->phone,
                $message,
                $meeting->tenant_id
            );

            if ($success) {
                // Descontar crédito de WhatsApp
                $tenantCredit = \App\Models\TenantMessagingCredit::where('tenant_id', $meeting->tenant_id)->first();
                if ($tenantCredit) {
                    $tenantCredit->consumeWhatsApp(1, "Resource assignment notification to planner #{$meeting->planner_user_id} for meeting #{$meeting->id}");
                }

                Log::info('Resource assignment notification sent', [
                    'allocation_id' => $allocation->id,
                    'meeting_id' => $meeting->id,
                    'planner_id' => $meeting->planner_user_id,
                ]);
            }

            return $success;
        } catch (\Exception $e) {
            Log::error('Failed to send resource assignment notification', [
                'allocation_id' => $allocation->id,
                'meeting_id' => $meeting->id,
                'planner_id' => $meeting->planner_user_id ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
