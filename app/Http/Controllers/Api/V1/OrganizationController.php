<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    /**
     * Get organization hierarchy tree for the tenant
     * Returns tree structure starting from top-level users (those without supervisor)
     * Excludes super admins (tenant_id = null)
     */
    public function tree(): JsonResponse
    {
        $user = request()->user();
        
        // Get all users from the tenant (excluding super admins)
        $users = User::where('tenant_id', $user->tenant_id)
            ->whereNull('reports_to') // Only root users (top level)
            ->with([
                'subordinates' => function($query) {
                    $query->with('subordinates'); // Recursive loading
                }
            ])
            ->get();
        
        $tree = $users->map(function ($user) {
            return $this->buildUserNode($user);
        });
        
        return response()->json([
            'data' => $tree
        ]);
    }
    
    /**
     * Build a single user node with its full hierarchy
     */
    protected function buildUserNode(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'cedula' => $user->cedula,
            'is_team_leader' => $user->is_team_leader,
            'roles' => $user->roles->pluck('name')->toArray(),
            'subordinates_count' => $user->subordinates->count(),
            'subordinates' => $user->subordinates->map(function ($subordinate) {
                return $this->buildUserNode($subordinate);
            })->toArray(),
            
            // Statistics
            'stats' => [
                'total_team_size' => $this->calculateTeamSize($user),
                'direct_reports' => $user->subordinates->count(),
                'meetings_planned' => $user->plannedMeetings()->count(),
                'commitments_assigned' => $user->assignedCommitments()->count(),
                'commitments_completed' => $user->assignedCommitments()->where('status', 'completed')->count(),
            ],
        ];
    }
    
    /**
     * Calculate total team size including all nested subordinates
     */
    protected function calculateTeamSize(User $user): int
    {
        $count = $user->subordinates->count();
        
        foreach ($user->subordinates as $subordinate) {
            $count += $this->calculateTeamSize($subordinate);
        }
        
        return $count;
    }
    
    /**
     * Get flat list of all users in tenant with their supervisor info
     */
    public function list(): JsonResponse
    {
        $user = request()->user();
        
        $users = User::where('tenant_id', $user->tenant_id)
            ->with(['supervisor:id,name,email', 'roles', 'subordinates']) // Eager load subordinates
            ->get()
            ->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'cedula' => $user->cedula,
                    'is_team_leader' => $user->is_team_leader,
                    'reports_to' => $user->reports_to,
                    'supervisor' => $user->supervisor ? [
                        'id' => $user->supervisor->id,
                        'name' => $user->supervisor->name,
                        'email' => $user->supervisor->email,
                    ] : null,
                    'roles' => $user->roles->pluck('name')->toArray(),
                    'subordinates_count' => $user->subordinates->count(), // Count loaded relationship
                ];
            });
        
        return response()->json([
            'data' => $users
        ]);
    }
    
    /**
     * Get user's team hierarchy with authenticated user as root
     * Returns the current user with all their subordinates (recursively loaded)
     */
    public function myTeam(): JsonResponse
    {
        $user = request()->user();
        
        // Load the authenticated user with all subordinates recursively
        $userWithTeam = User::where('id', $user->id)
            ->with([
                'roles',
                'subordinates' => function($query) {
                    $query->with('subordinates'); // Recursive loading
                }
            ])
            ->first();
        
        // Build the complete tree starting from authenticated user
        $tree = $this->buildUserNode($userWithTeam);
        
        return response()->json([
            'data' => $tree
        ]);
    }
    
    /**
     * Get user's chain of command (all supervisors up to root)
     */
    public function chainOfCommand(): JsonResponse
    {
        $user = request()->user();
        $chain = [];
        $current = $user;
        
        while ($current->supervisor) {
            $supervisor = $current->supervisor;
            $chain[] = [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'email' => $supervisor->email,
                'is_team_leader' => $supervisor->is_team_leader,
                'roles' => $supervisor->roles->pluck('name')->toArray(),
            ];
            $current = $supervisor;
        }
        
        return response()->json([
            'data' => $chain
        ]);
    }
    
    /**
     * Get list of potential supervisors (for user creation/editing)
     * Returns all team leaders in the tenant, optionally excluding a specific user
     * to prevent circular references when editing
     */
    public function potentialSupervisors(): JsonResponse
    {
        $user = request()->user();
        $excludeUserId = request()->query('exclude_user_id');
        
        $query = User::where('tenant_id', $user->tenant_id)
            ->where('is_team_leader', true)
            ->with('roles');
        
        // Exclude the user being edited to prevent them from reporting to themselves
        if ($excludeUserId) {
            $query->where('id', '!=', $excludeUserId);
            
            // Also exclude all subordinates of the user being edited
            // to prevent circular references (A reports to B, B reports to A)
            $userBeingEdited = User::find($excludeUserId);
            if ($userBeingEdited) {
                $subordinateIds = $this->getAllSubordinateIds($userBeingEdited);
                if (!empty($subordinateIds)) {
                    $query->whereNotIn('id', $subordinateIds);
                }
            }
        }
        
        $supervisors = $query->get()
            ->map(function ($supervisor) {
                return [
                    'id' => $supervisor->id,
                    'name' => $supervisor->name,
                    'email' => $supervisor->email,
                    'phone' => $supervisor->phone,
                    'roles' => $supervisor->roles->pluck('name')->toArray(),
                    'subordinates_count' => $supervisor->subordinates()->count(),
                ];
            });
        
        return response()->json([
            'data' => $supervisors
        ]);
    }
    
    /**
     * Get all subordinate IDs recursively (helper method)
     */
    protected function getAllSubordinateIds(User $user): array
    {
        $ids = [];
        
        foreach ($user->subordinates as $subordinate) {
            $ids[] = $subordinate->id;
            $ids = array_merge($ids, $this->getAllSubordinateIds($subordinate));
        }
        
        return $ids;
    }
}
