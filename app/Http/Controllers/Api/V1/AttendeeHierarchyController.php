<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendeeHierarchy;
use App\Services\AttendeeHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendeeHierarchyController extends Controller
{
    protected AttendeeHierarchyService $hierarchyService;

    public function __construct(AttendeeHierarchyService $hierarchyService)
    {
        $this->hierarchyService = $hierarchyService;
    }

    /**
     * Get attendee hierarchy tree
     */
    public function tree(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        
        if (!$tenant) {
            return response()->json([
                'message' => 'No tenant associated with this user.'
            ], 403);
        }

        $rootCedula = $request->query('root_cedula');
        $tree = $this->hierarchyService->getAttendeeTree($tenant, $rootCedula);

        return response()->json([
            'data' => $tree,
            'hierarchy_config' => [
                'mode' => $tenant->hierarchy_mode,
                'auto_assign' => $tenant->auto_assign_hierarchy,
                'conflict_resolution' => $tenant->hierarchy_conflict_resolution,
                'require_config' => $tenant->require_hierarchy_config,
            ]
        ]);
    }

    /**
     * Get attendee relationships (supervisors and subordinates)
     */
    public function relationships(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $cedula = $request->query('cedula');

        if (!$cedula) {
            return response()->json([
                'message' => 'Cédula is required'
            ], 400);
        }

        // Get as subordinate (who supervises this person)
        $supervisors = AttendeeHierarchy::where('tenant_id', $tenant->id)
            ->where('attendee_cedula', $cedula)
            ->active()
            ->orderByDesc('relationship_strength')
            ->get()
            ->map(function ($hierarchy) {
                return [
                    'cedula' => $hierarchy->supervisor_cedula,
                    'name' => $hierarchy->supervisor_name,
                    'relationship_strength' => $hierarchy->relationship_strength,
                    'last_interaction' => $hierarchy->last_interaction?->toDateString(),
                    'is_primary' => $hierarchy->is_primary,
                    'context' => $hierarchy->context,
                ];
            });

        // Get as supervisor (who does this person supervise)
        $subordinates = AttendeeHierarchy::where('tenant_id', $tenant->id)
            ->where('supervisor_cedula', $cedula)
            ->active()
            ->orderByDesc('relationship_strength')
            ->get()
            ->map(function ($hierarchy) {
                return [
                    'cedula' => $hierarchy->attendee_cedula,
                    'name' => $hierarchy->attendee_name,
                    'email' => $hierarchy->attendee_email,
                    'phone' => $hierarchy->attendee_phone,
                    'relationship_strength' => $hierarchy->relationship_strength,
                    'last_interaction' => $hierarchy->last_interaction?->toDateString(),
                    'is_primary' => $hierarchy->is_primary,
                    'context' => $hierarchy->context,
                ];
            });

        return response()->json([
            'data' => [
                'cedula' => $cedula,
                'supervisors' => $supervisors,
                'subordinates' => $subordinates,
                'primary_supervisor' => $supervisors->where('is_primary', true)->first(),
            ]
        ]);
    }

    /**
     * Update hierarchy relationship (set as primary, activate/deactivate)
     */
    public function update(Request $request, AttendeeHierarchy $hierarchy): JsonResponse
    {
        $request->validate([
            'is_primary' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'context' => 'nullable|string|max:255',
        ]);

        // If setting as primary, remove primary flag from other relationships
        if ($request->has('is_primary') && $request->is_primary) {
            AttendeeHierarchy::where('tenant_id', $hierarchy->tenant_id)
                ->where('attendee_cedula', $hierarchy->attendee_cedula)
                ->where('id', '!=', $hierarchy->id)
                ->update(['is_primary' => false]);
        }

        $hierarchy->update($request->only(['is_primary', 'is_active', 'context']));

        return response()->json([
            'data' => $hierarchy,
            'message' => 'Hierarchy relationship updated successfully'
        ]);
    }

    /**
     * Delete hierarchy relationship
     */
    public function destroy(AttendeeHierarchy $hierarchy): JsonResponse
    {
        $hierarchy->delete();

        return response()->json([
            'message' => 'Hierarchy relationship deleted successfully'
        ]);
    }

    /**
     * Get hierarchy statistics
     */
    public function stats(): JsonResponse
    {
        $tenant = app('tenant');

        $stats = [
            'total_relationships' => AttendeeHierarchy::where('tenant_id', $tenant->id)->active()->count(),
            'unique_attendees' => AttendeeHierarchy::where('tenant_id', $tenant->id)
                ->active()
                ->distinct('attendee_cedula')
                ->count('attendee_cedula'),
            'unique_supervisors' => AttendeeHierarchy::where('tenant_id', $tenant->id)
                ->active()
                ->distinct('supervisor_cedula')
                ->count('supervisor_cedula'),
            'multiple_supervisors' => AttendeeHierarchy::where('tenant_id', $tenant->id)
                ->active()
                ->groupBy('attendee_cedula')
                ->havingRaw('COUNT(*) > 1')
                ->count(),
            'config' => [
                'mode' => $tenant->hierarchy_mode,
                'auto_assign' => $tenant->auto_assign_hierarchy,
                'conflict_resolution' => $tenant->hierarchy_conflict_resolution,
                'require_config' => $tenant->require_hierarchy_config,
            ]
        ];

        return response()->json([
            'data' => $stats
        ]);
    }
}
