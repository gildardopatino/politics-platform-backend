<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenant\StoreTenantRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Models\Tenant;
use App\Services\TenantProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\QueryBuilder;

class TenantController extends Controller
{
    public function __construct(private readonly TenantProvisioningService $provisioning) {}

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
            ],
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
                'message' => 'Solo el superadministrador puede crear tenants',
            ], 403);
        }

        try {
            // Toda el alta (tenant + créditos + roles clonados + admin inicial)
            // vive en el servicio, que comparte con DemoDataSeeder (Spec 0003).
            $tenant = $this->provisioning->provision($request->validated());
            $admin = $this->provisioning->adminDe($tenant);

            Log::info('Tenant created with admin and messaging credits', [
                'tenant_id' => $tenant->id,
                'admin_user_id' => $admin?->id,
                'created_by' => $user->id,
            ]);

            return response()->json([
                'data' => new TenantResource($tenant->load('messagingCredit')),
                'admin' => [
                    'id' => $admin?->id,
                    'name' => $admin?->name,
                    'email' => $admin?->email,
                ],
                'message' => 'Tenant created successfully',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating tenant', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            return response()->json([
                'message' => 'Error creating tenant',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json([
            'data' => new TenantResource($tenant->load([
                'users',
                'meetings',
                'campaigns',
                'messagingCredit',
                'whatsappInstances',
            ])),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTenantRequest $request, Tenant $tenant): JsonResponse
    {
        $tenant->update($request->validated());

        return response()->json([
            'data' => new TenantResource($tenant->load([
                'messagingCredit',
                'whatsappInstances',
            ])),
            'message' => 'Tenant updated successfully',
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Tenant $tenant): JsonResponse
    {
        $tenant->delete();

        return response()->json([
            'message' => 'Tenant deleted successfully',
        ]);
    }
}
