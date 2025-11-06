<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RoleController extends Controller
{
    /**
     * Display a listing of roles for the current tenant
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->input('per_page', 15);
        
        $query = Role::with('permissions:id,name');

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $roles = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $roles->items(),
            'pagination' => [
                'total' => $roles->total(),
                'per_page' => $roles->perPage(),
                'current_page' => $roles->currentPage(),
                'last_page' => $roles->lastPage(),
                'from' => $roles->firstItem(),
                'to' => $roles->lastItem(),
            ],
        ]);
    }

    /**
     * Store a newly created role
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name,NULL,id,tenant_id,' . auth()->user()->tenant_id,
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ], [
            'name.unique' => 'Ya existe un rol con este nombre en tu organización',
            'permissions.*.exists' => 'Uno o más permisos seleccionados no existen',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $role = Role::create([
            'name' => $request->name,
            'guard_name' => 'api',
            'tenant_id' => auth()->user()->tenant_id,
        ]);

        if ($request->has('permissions')) {
            $permissions = Permission::whereIn('id', $request->permissions)->get();
            $role->syncPermissions($permissions);
        }

        $role->load('permissions:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Rol creado exitosamente',
            'data' => $role,
        ], 201);
    }

    /**
     * Display the specified role
     */
    public function show(Role $role): JsonResponse
    {
        $role->load([
            'permissions:id,name',
            'users:id,name,email'
        ]);

        // Count users with this role
        $role->users_count = $role->users->count();

        return response()->json([
            'success' => true,
            'data' => $role,
        ]);
    }

    /**
     * Update the specified role
     */
    public function update(Request $request, Role $role): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:roles,name,' . $role->id . ',id,tenant_id,' . auth()->user()->tenant_id,
            'permissions' => 'nullable|array',
            'permissions.*' => 'exists:permissions,id',
        ], [
            'name.unique' => 'Ya existe un rol con este nombre en tu organización',
            'permissions.*.exists' => 'Uno o más permisos seleccionados no existen',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $role->update([
            'name' => $request->name,
        ]);

        if ($request->has('permissions')) {
            $permissions = Permission::whereIn('id', $request->permissions)->get();
            $role->syncPermissions($permissions);
        }

        $role->load('permissions:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Rol actualizado exitosamente',
            'data' => $role,
        ]);
    }

    /**
     * Remove the specified role
     */
    public function destroy(Role $role): JsonResponse
    {
        // Check if role has users assigned
        $usersCount = $role->users()->count();
        
        if ($usersCount > 0) {
            return response()->json([
                'success' => false,
                'message' => "No se puede eliminar el rol porque tiene {$usersCount} usuario(s) asignado(s)",
            ], 422);
        }

        $role->delete();

        return response()->json([
            'success' => true,
            'message' => 'Rol eliminado exitosamente',
        ]);
    }

    /**
     * Assign or sync permissions to a role
     */
    public function assignPermissions(Request $request, Role $role): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'permissions' => 'required|array|min:1',
            'permissions.*' => 'exists:permissions,id',
        ], [
            'permissions.required' => 'Debe seleccionar al menos un permiso',
            'permissions.*.exists' => 'Uno o más permisos seleccionados no existen',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $permissions = Permission::whereIn('id', $request->permissions)->get();
        $role->syncPermissions($permissions);
        $role->load('permissions:id,name');

        return response()->json([
            'success' => true,
            'message' => 'Permisos asignados exitosamente',
            'data' => $role,
        ]);
    }
}
