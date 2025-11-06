<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PermissionController extends Controller
{
    /**
     * Display a listing of all available permissions
     * Permissions are global and not tenant-scoped
     */
    public function index(Request $request): JsonResponse
    {
        $query = Permission::query();

        // Optional grouping by category (extracted from permission name prefix)
        $groupByCategory = $request->boolean('group_by_category', false);

        if ($groupByCategory) {
            $permissions = Permission::all()->groupBy(function ($permission) {
                // Extract category from permission name (e.g., "view_users" -> "users")
                $parts = explode('_', $permission->name);
                return count($parts) > 1 ? $parts[1] : 'other';
            })->map(function ($group, $category) {
                return [
                    'category' => $category,
                    'permissions' => $group->map(function ($permission) {
                        return [
                            'id' => $permission->id,
                            'name' => $permission->name,
                            'display_name' => $this->formatPermissionName($permission->name),
                        ];
                    })->values(),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => $permissions,
            ]);
        }

        // Simple list
        $permissions = Permission::all()->map(function ($permission) {
            return [
                'id' => $permission->id,
                'name' => $permission->name,
                'display_name' => $this->formatPermissionName($permission->name),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $permissions,
        ]);
    }

    /**
     * Format permission name for display
     */
    private function formatPermissionName(string $name): string
    {
        // Convert "view_users" to "Ver Usuarios"
        $translations = [
            'view' => 'Ver',
            'create' => 'Crear',
            'edit' => 'Editar',
            'delete' => 'Eliminar',
            'users' => 'Usuarios',
            'meetings' => 'Reuniones',
            'campaigns' => 'Campañas',
            'commitments' => 'Compromisos',
            'resources' => 'Recursos',
            'reports' => 'Reportes',
            'voters' => 'Votantes',
            'surveys' => 'Encuestas',
            'calls' => 'Llamadas',
            'roles' => 'Roles',
        ];

        $parts = explode('_', $name);
        $translated = array_map(function ($part) use ($translations) {
            return $translations[$part] ?? ucfirst($part);
        }, $parts);

        return implode(' ', $translated);
    }
}
