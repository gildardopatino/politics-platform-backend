<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\User\StoreUserRequest;
use App\Http\Requests\Api\V1\User\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\EmailNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\QueryBuilder\QueryBuilder;

class UserController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $users = QueryBuilder::for(User::class)
            ->with([
                'roles', // Always load roles by default
                // NEW: Always load multiple geographic relationships
                'departments:id,nombre,codigo',
                'municipalities:id,nombre,codigo',
                'communes:id,nombre,codigo',
                'barrios:id,nombre,codigo',
                'corregimientos:id,nombre,codigo',
                'veredas:id,nombre,codigo',
                // OLD: Single geographic relationships (for backward compatibility)
                'department:id,nombre',
                'municipality:id,nombre',
                'commune:id,nombre',
                'barrio:id,nombre',
                'corregimiento:id,nombre',
                'vereda:id,nombre',
            ])
            ->allowedFilters(['name', 'email', 'is_team_leader'])
            ->allowedIncludes([
                'tenant',
                'supervisor',
                'permissions',
            ])
            ->allowedSorts(['name', 'created_at', 'email'])
            ->paginate(request('per_page', 15));

        return response()->json([
            'data' => UserResource::collection($users->items()),
            'meta' => [
                'total' => $users->total(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
            ]
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreUserRequest $request, EmailNotificationService $emailService): JsonResponse
    {
        /** @var \App\Models\User $authUser */
        $authUser = $request->user();
        
        // Use provided password or generate a random one
        $password = $request->filled('password') 
            ? $request->password 
            : Str::password(12, true, true, false, false);
        
        $user = User::create([
            'tenant_id' => $request->tenant_id ?? $authUser->tenant_id,
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($password),
            'phone' => $request->phone,
            'cedula' => $request->cedula,
            'is_team_leader' => $request->is_team_leader ?? false,
            'reports_to' => $request->reports_to,
            'created_by_user_id' => $authUser->id,
            // Keep old single fields for backward compatibility
            'department_id' => $request->department_id,
            'municipality_id' => $request->municipality_id,
            'commune_id' => $request->commune_id,
            'barrio_id' => $request->barrio_id,
            'corregimiento_id' => $request->corregimiento_id,
            'vereda_id' => $request->vereda_id,
        ]);

        // Handle roles assignment - support both 'role_id' and 'roles' array
        if ($request->filled('roles') && is_array($request->roles)) {
            $user->syncRoles($request->roles);
        } elseif ($request->filled('role_id')) {
            $user->syncRoles([$request->role_id]);
        }

        // Sync many-to-many geographic assignments
        if ($request->filled('department_ids') && is_array($request->department_ids)) {
            $data = [];
            foreach ($request->department_ids as $deptId) {
                $data[$deptId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Department'
                ];
            }
            $user->departments()->sync($data);
        }
        if ($request->filled('municipality_ids') && is_array($request->municipality_ids)) {
            $data = [];
            foreach ($request->municipality_ids as $muniId) {
                $data[$muniId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Municipality'
                ];
            }
            $user->municipalities()->sync($data);
        }
        if ($request->filled('commune_ids') && is_array($request->commune_ids)) {
            $data = [];
            foreach ($request->commune_ids as $communeId) {
                $data[$communeId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Commune'
                ];
            }
            $user->communes()->sync($data);
        }
        if ($request->filled('barrio_ids') && is_array($request->barrio_ids)) {
            $data = [];
            foreach ($request->barrio_ids as $barrioId) {
                $data[$barrioId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Barrio'
                ];
            }
            $user->barrios()->sync($data);
        }
        if ($request->filled('corregimiento_ids') && is_array($request->corregimiento_ids)) {
            $data = [];
            foreach ($request->corregimiento_ids as $correId) {
                $data[$correId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Corregimiento'
                ];
            }
            $user->corregimientos()->sync($data);
        }
        if ($request->filled('vereda_ids') && is_array($request->vereda_ids)) {
            $data = [];
            foreach ($request->vereda_ids as $veredaId) {
                $data[$veredaId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Vereda'
                ];
            }
            $user->veredas()->sync($data);
        }

        // Get JWT token from Authorization header
        $token = $request->bearerToken();

        // Send welcome email with credentials using the authenticated user's token
        $emailSent = $emailService->sendWelcomeEmail(
            $user->email,
            $user->name,
            $password,
            $token
        );

        // Load all relationships for the response
        $user->load([
            'roles',
            'departments:id,nombre,codigo',
            'municipalities:id,nombre,codigo',
            'communes:id,nombre,codigo',
            'barrios:id,nombre,codigo',
            'corregimientos:id,nombre,codigo',
            'veredas:id,nombre,codigo',
            'department:id,nombre',
            'municipality:id,nombre',
            'commune:id,nombre',
            'barrio:id,nombre',
            'corregimiento:id,nombre',
            'vereda:id,nombre',
        ]);

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'User created successfully. ' . ($emailSent ? 'Welcome email sent.' : 'Failed to send welcome email.'),
            'email_sent' => $emailSent
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(User $user): JsonResponse
    {
        $user->load([
            'tenant',
            'supervisor',
            'subordinates',
            'roles',
            'permissions',
            // OLD: Single geographic relationships (deprecated)
            'department:id,nombre',
            'municipality:id,nombre',
            'commune:id,nombre',
            'barrio:id,nombre',
            'corregimiento:id,nombre',
            'vereda:id,nombre',
            // NEW: Multiple geographic relationships
            'departments:id,nombre,codigo',
            'municipalities:id,nombre,codigo',
            'communes:id,nombre,codigo',
            'barrios:id,nombre,codigo',
            'corregimientos:id,nombre,codigo',
            'veredas:id,nombre,codigo'
        ]);

        return response()->json([
            'data' => new UserResource($user)
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $data = $request->except(['password', 'roles', 'role_id', 'department_ids', 'municipality_ids', 'commune_ids', 'barrio_ids', 'corregimiento_ids', 'vereda_ids']);
        
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        // Handle roles assignment - support both 'role_id' and 'roles' array
        if ($request->filled('roles') && is_array($request->roles)) {
            $user->syncRoles($request->roles);
        } elseif ($request->filled('role_id')) {
            $user->syncRoles([$request->role_id]);
        }

        // Sync many-to-many geographic assignments
        if ($request->filled('department_ids') && is_array($request->department_ids)) {
            $data = [];
            foreach ($request->department_ids as $deptId) {
                $data[$deptId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Department'
                ];
            }
            $user->departments()->sync($data);
        }
        if ($request->filled('municipality_ids') && is_array($request->municipality_ids)) {
            $data = [];
            foreach ($request->municipality_ids as $muniId) {
                $data[$muniId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Municipality'
                ];
            }
            $user->municipalities()->sync($data);
        }
        if ($request->filled('commune_ids') && is_array($request->commune_ids)) {
            $data = [];
            foreach ($request->commune_ids as $communeId) {
                $data[$communeId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Commune'
                ];
            }
            $user->communes()->sync($data);
        }
        if ($request->filled('barrio_ids') && is_array($request->barrio_ids)) {
            $data = [];
            foreach ($request->barrio_ids as $barrioId) {
                $data[$barrioId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Barrio'
                ];
            }
            $user->barrios()->sync($data);
        }
        if ($request->filled('corregimiento_ids') && is_array($request->corregimiento_ids)) {
            $data = [];
            foreach ($request->corregimiento_ids as $correId) {
                $data[$correId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Corregimiento'
                ];
            }
            $user->corregimientos()->sync($data);
        }
        if ($request->filled('vereda_ids') && is_array($request->vereda_ids)) {
            $data = [];
            foreach ($request->vereda_ids as $veredaId) {
                $data[$veredaId] = [
                    'tenant_id' => $user->tenant_id,
                    'assignable_type' => 'App\\Models\\Vereda'
                ];
            }
            $user->veredas()->sync($data);
        }

        // Load all relationships for the response
        $user->load([
            'roles',
            'departments:id,nombre,codigo',
            'municipalities:id,nombre,codigo',
            'communes:id,nombre,codigo',
            'barrios:id,nombre,codigo',
            'corregimientos:id,nombre,codigo',
            'veredas:id,nombre,codigo',
            'department:id,nombre',
            'municipality:id,nombre',
            'commune:id,nombre',
            'barrio:id,nombre',
            'corregimiento:id,nombre',
            'vereda:id,nombre',
        ]);

        return response()->json([
            'data' => new UserResource($user),
            'message' => 'User updated successfully'
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(User $user): JsonResponse
    {
        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully'
        ]);
    }

    /**
     * Get user's team hierarchy
     */
    public function team(User $user): JsonResponse
    {
        $team = $user->getTeamHierarchy();

        return response()->json([
            'data' => $team
        ]);
    }
}
