<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenant\StoreTenantRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Models\Tenant;
use App\Models\TenantMessagingCredit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\QueryBuilder;

class TenantController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $tenants = QueryBuilder::for(Tenant::class)
            ->with('messagingCredit') // Load messaging credits
            ->allowedFilters(['nombre', 'tipo_cargo', 'identificacion'])
            ->allowedSorts(['nombre', 'created_at'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => TenantResource::collection($tenants->items()),
            'meta' => [
                'total' => $tenants->total(),
                'current_page' => $tenants->currentPage(),
                'last_page' => $tenants->lastPage(),
                'per_page' => $tenants->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        // Validate that only superadmin (tenant_id = null) can create tenants
        $user = auth('api')->user();
        if ($user->tenant_id !== null) {
            return response()->json([
                'message' => 'Solo el superadministrador puede crear tenants'
            ], 403);
        }

        DB::beginTransaction();
        try {
            $tenant = Tenant::create($request->validated());

            // Initialize messaging credits with values from request or defaults
            $emailsInitial = $request->input('initial_emails', 1000);
            $whatsappInitial = $request->input('initial_whatsapp', 500);

            TenantMessagingCredit::create([
                'tenant_id' => $tenant->id,
                'emails_available' => $emailsInitial,
                'whatsapp_available' => $whatsappInitial,
            ]);

            Log::info('Tenant created with messaging credits', [
                'tenant_id' => $tenant->id,
                'created_by' => $user->id,
                'emails' => $emailsInitial,
                'whatsapp' => $whatsappInitial,
            ]);

            DB::commit();

            return response()->json([
                'data' => new TenantResource($tenant->load('messagingCredit')),
                'message' => 'Tenant created successfully'
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating tenant', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);
            
            return response()->json([
                'message' => 'Error creating tenant',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'data' => new TenantResource($tenant->load(['users', 'meetings', 'campaigns', 'messagingCredit']))
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $tenant->update($request->validated());

        return response()->json([
            'data' => new TenantResource($tenant->load('messagingCredit')),
            'message' => 'Tenant updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tenant $tenant): JsonResponse
    {
        $tenant->delete();

        return response()->json([
            'message' => 'Tenant deleted successfully'
        ]);
    }
}
