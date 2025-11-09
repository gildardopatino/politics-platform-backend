<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Audit\AuditResource;
use Illuminate\Http\Request;
use OwenIt\Auditing\Models\Audit;

class AuditController extends Controller
{
    /**
     * Display a listing of the audits.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function index(Request $request)
    {
        // Check permission
        if ($response = $this->checkPermission()) {
            return $response;
        }

        $query = Audit::query()
            ->with(['user'])
            ->orderBy('created_at', 'desc');
        // Filtro por tenant_id (multi-tenancy)
        // Solo filtra si hay un tenant disponible (omite super_admins)
        try {
            $tenant = app()->make('tenant');
            if ($tenant) {
                $tenantId = $tenant->id;
                $query->where(function ($q) use ($tenantId) {
                    // PostgreSQL: convertir TEXT a JSONB primero
                    $q->whereRaw("(new_values::jsonb->>'tenant_id')::int = ?", [$tenantId])
                      ->orWhereRaw("(old_values::jsonb->>'tenant_id')::int = ?", [$tenantId]);
                });
            }
        } catch (\Exception $e) {
            // No tenant bound, skip filtering (super admin case)
        }

        // Filtro por usuario
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filtro por modelo (auditable_type)
        if ($request->filled('model')) {
            $modelClass = 'App\\Models\\' . $request->model;
            $query->where('auditable_type', $modelClass);
        }

        // Filtro por tipo de evento (created, updated, deleted, restored)
        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        // Filtro por rango de fechas (start_date - end_date)
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        // Filtro por IP address
        if ($request->filled('ip_address')) {
            $query->where('ip_address', $request->ip_address);
        }

        // Filtro por ID específico del modelo auditado
        if ($request->filled('auditable_id')) {
            $query->where('auditable_id', $request->auditable_id);
        }

        // Paginación
        $perPage = $request->input('per_page', 15);
        $audits = $query->paginate($perPage);

        return AuditResource::collection($audits);
    }

    /**
     * Check if user has permission to view audits
     *
     * @return \Illuminate\Http\JsonResponse|null
     */
    private function checkPermission()
    {
        $user = auth('api')->user();
        
        if (!$user || !$user->can('view_audits')) {
            return response()->json([
                'message' => 'No tienes permiso para ver las auditorías.'
            ], 403);
        }
        
        return null;
    }

    /**
     * Display the specified audit.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function show($id)
    {
        if ($response = $this->checkPermission()) {
            return $response;
        }

        $audit = Audit::with(['user'])->findOrFail($id);

        return new AuditResource($audit);
    }

    /**
     * Get audits for a specific user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $userId
     * @return \Illuminate\Http\JsonResponse
     */
    public function byUser(Request $request, $userId)
    {
        if ($response = $this->checkPermission()) {
            return $response;
        }

        $query = Audit::query()
            ->with(['user'])
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc');

        // Filtros adicionales opcionales
        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        if ($request->filled('event')) {
            $query->where('event', $request->event);
        }

        $perPage = $request->input('per_page', 15);
        $audits = $query->paginate($perPage);

        return AuditResource::collection($audits);
    }

    /**
     * Get audits for a specific model instance.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $model
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function byModel(Request $request, $model, $id)
    {
        if ($response = $this->checkPermission()) {
            return $response;
        }

        $modelClass = 'App\\Models\\' . ucfirst($model);

        $query = Audit::query()
            ->with(['user'])
            ->where('auditable_type', $modelClass)
            ->where('auditable_id', $id)
            ->orderBy('created_at', 'desc');

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $perPage = $request->input('per_page', 15);
        $audits = $query->paginate($perPage);

        return AuditResource::collection($audits);
    }

    /**
     * Get statistics about audits.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function statistics(Request $request)
    {
        if ($response = $this->checkPermission()) {
            return $response;
        }

        $query = Audit::query();

        // Filtro por tenant
        try {
            $tenant = app()->make('tenant');
            if ($tenant) {
                $tenantId = $tenant->id;
                $query->where(function ($q) use ($tenantId) {
                    $q->whereRaw("(new_values::jsonb->>'tenant_id')::int = ?", [$tenantId])
                      ->orWhereRaw("(old_values::jsonb->>'tenant_id')::int = ?", [$tenantId]);
                });
            }
        } catch (\Exception $e) {
            // No tenant bound, skip filtering
        }

        if ($request->filled('start_date')) {
            $query->whereDate('created_at', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('created_at', '<=', $request->end_date);
        }

        $statistics = [
            'total_audits' => $query->count(),
            'by_event' => $query->clone()->selectRaw('event, COUNT(*) as count')
                ->groupBy('event')
                ->pluck('count', 'event'),
            'by_model' => $query->clone()->selectRaw('auditable_type, COUNT(*) as count')
                ->groupBy('auditable_type')
                ->pluck('count', 'auditable_type'),
            'top_users' => $query->clone()->selectRaw('user_id, COUNT(*) as count')
                ->groupBy('user_id')
                ->orderByDesc('count')
                ->limit(10)
                ->with('user:id,name')
                ->get()
                ->map(function ($item) {
                    return [
                        'user_id' => $item->user_id,
                        'count' => $item->count,
                    ];
                }),
            'by_date' => $query->clone()->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->limit(30)
                ->pluck('count', 'date'),
        ];

        return response()->json([
            'success' => true,
            'data' => $statistics,
        ]);
    }
}
