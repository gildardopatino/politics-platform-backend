<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Commitment;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\ResourceAllocation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get dashboard statistics
     */
    public function index(): JsonResponse
    {
        $user = request()->user();
        
        // Stats generales
        $stats = [
            // Contadores principales
            'totals' => [
                'meetings' => Meeting::count(),
                'meetings_scheduled' => Meeting::where('status', 'scheduled')->count(),
                'meetings_completed' => Meeting::where('status', 'completed')->count(),
                'meetings_cancelled' => Meeting::where('status', 'cancelled')->count(),
                'attendees' => MeetingAttendee::count(),
                'attendees_checked_in' => MeetingAttendee::where('checked_in', true)->count(),
                'commitments' => Commitment::count(),
                'commitments_pending' => Commitment::where('status', 'pending')->count(),
                'commitments_in_progress' => Commitment::where('status', 'in_progress')->count(),
                'commitments_completed' => Commitment::where('status', 'completed')->count(),
                'commitments_overdue' => Commitment::overdue()->count(),
                'campaigns' => Campaign::count(),
                'campaigns_sent' => Campaign::where('status', 'sent')->count(),
                'users' => User::count(),
                'team_leaders' => User::where('is_team_leader', true)->count(),
            ],
            
            // Compromisos por prioridad
            'commitments_by_priority' => Commitment::select('priority_id', DB::raw('count(*) as total'))
                ->with('priority:id,name,color')
                ->groupBy('priority_id')
                ->get()
                ->map(function ($item) {
                    return [
                        'priority_id' => $item->priority_id,
                        'priority_name' => $item->priority->name ?? 'Sin prioridad',
                        'priority_color' => $item->priority->color ?? '#808080',
                        'total' => $item->total,
                    ];
                }),
            
            // Reuniones por mes (últimos 12 meses)
            'meetings_by_month' => Meeting::select(
                    DB::raw('EXTRACT(YEAR FROM starts_at) as year'),
                    DB::raw('EXTRACT(MONTH FROM starts_at) as month'),
                    DB::raw('count(*) as total')
                )
                ->where('starts_at', '>=', now()->subMonths(12))
                ->groupBy('year', 'month')
                ->orderBy('year', 'asc')
                ->orderBy('month', 'asc')
                ->get()
                ->map(function ($item) {
                    return [
                        'year' => (int) $item->year,
                        'month' => (int) $item->month,
                        'month_name' => now()->setMonth((int) $item->month)->locale('es')->translatedFormat('F'),
                        'total' => $item->total,
                    ];
                }),
            
            // Asistentes por mes (últimos 12 meses)
            'attendees_by_month' => MeetingAttendee::select(
                    DB::raw('EXTRACT(YEAR FROM created_at) as year'),
                    DB::raw('EXTRACT(MONTH FROM created_at) as month'),
                    DB::raw('count(*) as total')
                )
                ->where('created_at', '>=', now()->subMonths(12))
                ->groupBy('year', 'month')
                ->orderBy('year', 'asc')
                ->orderBy('month', 'asc')
                ->get()
                ->map(function ($item) {
                    return [
                        'year' => (int) $item->year,
                        'month' => (int) $item->month,
                        'month_name' => now()->setMonth((int) $item->month)->locale('es')->translatedFormat('F'),
                        'total' => $item->total,
                    ];
                }),
            
            // Promedio de asistentes por reunión
            'avg_attendees_per_meeting' => round(
                MeetingAttendee::count() / max(Meeting::count(), 1),
                2
            ),
            
            // Promedio de compromisos por reunión
            'avg_commitments_per_meeting' => round(
                Commitment::count() / max(Meeting::count(), 1),
                2
            ),
            
            // Top 5 reuniones con más asistentes
            'top_meetings_by_attendees' => Meeting::select('meetings.*', DB::raw('count(meeting_attendees.id) as attendees_count'))
                ->leftJoin('meeting_attendees', 'meetings.id', '=', 'meeting_attendees.meeting_id')
                ->groupBy('meetings.id')
                ->orderBy('attendees_count', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($meeting) {
                    return [
                        'id' => $meeting->id,
                        'title' => $meeting->title,
                        'starts_at' => $meeting->starts_at?->toISOString(),
                        'attendees_count' => $meeting->attendees_count,
                    ];
                }),
            
            // Top 5 usuarios con más compromisos asignados
            'top_users_by_commitments' => User::select('users.*', DB::raw('count(commitments.id) as commitments_count'))
                ->leftJoin('commitments', 'users.id', '=', 'commitments.assigned_user_id')
                ->groupBy('users.id')
                ->orderBy('commitments_count', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($user) {
                    return [
                        'id' => $user->id,
                        'name' => $user->name,
                        'commitments_count' => $user->commitments_count,
                    ];
                }),
            
            // Tasa de cumplimiento de compromisos
            'commitment_completion_rate' => [
                'total' => Commitment::count(),
                'completed' => Commitment::where('status', 'completed')->count(),
                'rate' => Commitment::count() > 0 
                    ? round((Commitment::where('status', 'completed')->count() / Commitment::count()) * 100, 2)
                    : 0,
            ],
            
            // Distribución de recursos por tipo
            'resources_by_type' => ResourceAllocation::select('type', DB::raw('count(*) as total'), DB::raw('sum(amount) as total_amount'))
                ->groupBy('type')
                ->get()
                ->map(function ($item) {
                    return [
                        'type' => $item->type,
                        'total' => $item->total,
                        'total_amount' => $item->total_amount ?? 0,
                    ];
                }),
            
            // Total presupuesto asignado
            'total_budget' => ResourceAllocation::where('type', 'cash')->sum('amount'),
            
            // Próximas reuniones (5)
            'upcoming_meetings' => Meeting::where('starts_at', '>', now())
                ->where('status', 'scheduled')
                ->orderBy('starts_at', 'asc')
                ->limit(5)
                ->get(['id', 'title', 'starts_at', 'lugar_nombre']),
            
            // Compromisos vencidos recientes
            'recent_overdue_commitments' => Commitment::overdue()
                ->with(['meeting:id,title', 'assignedUser:id,name', 'priority:id,name,color'])
                ->orderBy('due_date', 'desc')
                ->limit(5)
                ->get()
                ->map(function ($commitment) {
                    return [
                        'id' => $commitment->id,
                        'description' => $commitment->description,
                        'due_date' => $commitment->due_date?->toISOString(),
                        'days_overdue' => now()->diffInDays($commitment->due_date, false),
                        'meeting' => $commitment->meeting ? [
                            'id' => $commitment->meeting->id,
                            'title' => $commitment->meeting->title,
                        ] : null,
                        'assigned_user' => $commitment->assignedUser ? [
                            'id' => $commitment->assignedUser->id,
                            'name' => $commitment->assignedUser->name,
                        ] : null,
                        'priority' => $commitment->priority ? [
                            'name' => $commitment->priority->name,
                            'color' => $commitment->priority->color,
                        ] : null,
                    ];
                }),
        ];
        
        return response()->json([
            'data' => $stats
        ]);
    }
    
    /**
     * Get calendar events (meetings and commitments)
     */
    public function calendar(): JsonResponse
    {
        $start = request('start', now()->startOfMonth()->toDateString());
        $end = request('end', now()->endOfMonth()->toDateString());
        
        // Obtener reuniones en el rango de fechas
        $meetings = Meeting::whereBetween('starts_at', [$start, $end])
            ->with(['planner:id,name', 'municipality:id,nombre'])
            ->get()
            ->map(function ($meeting) {
                return [
                    'type' => 'meeting',
                    'id' => $meeting->id,
                    'title' => $meeting->title,
                    'description' => $meeting->description,
                    'start' => $meeting->starts_at?->toISOString(),
                    'end' => $meeting->ends_at?->toISOString(),
                    'location' => $meeting->lugar_nombre,
                    'municipality' => $meeting->municipality?->nombre,
                    'status' => $meeting->status,
                    'planner' => $meeting->planner ? [
                        'id' => $meeting->planner->id,
                        'name' => $meeting->planner->name,
                    ] : null,
                    'color' => $this->getMeetingColor($meeting->status),
                ];
            });
        
        // Obtener compromisos en el rango de fechas
        $commitments = Commitment::whereBetween('due_date', [$start, $end])
            ->with(['meeting:id,title', 'assignedUser:id,name', 'priority:id,name,color'])
            ->get()
            ->map(function ($commitment) {
                return [
                    'type' => 'commitment',
                    'id' => $commitment->id,
                    'title' => $commitment->description,
                    'description' => $commitment->notes,
                    'start' => $commitment->due_date?->toISOString(),
                    'end' => $commitment->due_date?->toISOString(),
                    'status' => $commitment->status,
                    'meeting' => $commitment->meeting ? [
                        'id' => $commitment->meeting->id,
                        'title' => $commitment->meeting->title,
                    ] : null,
                    'assigned_user' => $commitment->assignedUser ? [
                        'id' => $commitment->assignedUser->id,
                        'name' => $commitment->assignedUser->name,
                    ] : null,
                    'priority' => $commitment->priority ? [
                        'id' => $commitment->priority->id,
                        'name' => $commitment->priority->name,
                        'color' => $commitment->priority->color,
                    ] : null,
                    'color' => $commitment->priority?->color ?? '#808080',
                ];
            });
        
        return response()->json([
            'data' => [
                'meetings' => $meetings,
                'commitments' => $commitments,
                'all_events' => $meetings->concat($commitments)->sortBy('start')->values(),
            ]
        ]);
    }
    
    /**
     * Get color based on meeting status
     */
    private function getMeetingColor(string $status): string
    {
        return match($status) {
            'scheduled' => '#3B82F6', // Azul
            'in_progress' => '#F59E0B', // Naranja
            'completed' => '#10B981', // Verde
            'cancelled' => '#EF4444', // Rojo
            default => '#6B7280', // Gris
        };
    }
}
