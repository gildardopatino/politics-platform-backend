<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Tenant\StoreTenantRequest;
use App\Http\Requests\Api\V1\Tenant\UpdateTenantRequest;
use App\Http\Resources\Api\V1\TenantResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMessagingCredit;
use App\Models\User;
use App\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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

        $validated = $request->validated();
        $tenantData = Arr::except($validated, [
            'admin_name', 'admin_email', 'admin_password', 'initial_emails', 'initial_whatsapp',
        ]);

        DB::beginTransaction();
        try {
            $tenant = Tenant::create($tenantData);

            // Initialize messaging credits with values from request or defaults
            $emailsInitial = $request->input('initial_emails', 1000);
            $whatsappInitial = $request->input('initial_whatsapp', 500);

            TenantMessagingCredit::create([
                'tenant_id' => $tenant->id,
                'emails_available' => $emailsInitial,
                'whatsapp_available' => $whatsappInitial,
            ]);

            // Provision the tenant's own role set and its initial administrator.
            $this->provisionTenantRoles($tenant);
            $admin = $this->createTenantAdmin($tenant, $validated);

            Log::info('Tenant created with admin and messaging credits', [
                'tenant_id' => $tenant->id,
                'admin_user_id' => $admin->id,
                'created_by' => $user->id,
                'emails' => $emailsInitial,
                'whatsapp' => $whatsappInitial,
            ]);

            DB::commit();

            return response()->json([
                'data' => new TenantResource($tenant->load('messagingCredit')),
                'admin' => [
                    'id' => $admin->id,
                    'name' => $admin->name,
                    'email' => $admin->email,
                ],
                'message' => 'Tenant created successfully',
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
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
     * Clone the global role templates (admin/coordinator/operator/viewer) into
     * dedicated, tenant-scoped roles so each tenant owns its own role set.
     */
    private function provisionTenantRoles(Tenant $tenant): void
    {
        $templateNames = ['admin', 'coordinator', 'operator', 'viewer'];

        $globalRoles = Role::withoutGlobalScope(TenantScope::class)
            ->whereNull('tenant_id')
            ->where('guard_name', 'api')
            ->whereIn('name', $templateNames)
            ->with('permissions')
            ->get();

        if ($globalRoles->isEmpty()) {
            // No global templates seeded — at minimum create an admin with every permission.
            $admin = Role::create(['name' => 'admin', 'guard_name' => 'api', 'tenant_id' => $tenant->id]);
            $admin->syncPermissions(Permission::all());

            return;
        }

        foreach ($globalRoles as $template) {
            $role = Role::create([
                'name' => $template->name,
                'guard_name' => 'api',
                'tenant_id' => $tenant->id,
            ]);
            $role->syncPermissions($template->permissions);
        }
    }

    /**
     * Create the tenant's initial administrator and assign its (tenant-scoped) admin role.
     */
    private function createTenantAdmin(Tenant $tenant, array $data): User
    {
        $admin = User::create([
            'tenant_id' => $tenant->id,
            'name' => $data['admin_name'],
            'email' => $data['admin_email'],
            'password' => Hash::make($data['admin_password']),
            'is_super_admin' => false,
        ]);

        $adminRole = Role::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('name', 'admin')
            ->where('guard_name', 'api')
            ->first();

        if ($adminRole) {
            $admin->assignRole($adminRole);
        }

        return $admin;
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
