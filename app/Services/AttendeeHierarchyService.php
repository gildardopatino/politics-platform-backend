<?php

namespace App\Services;

use App\Models\AttendeeHierarchy;
use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendeeHierarchyService
{
    /**
     * Procesar asignación de reunión y actualizar jerarquías si es necesario
     */
    public function processHierarchyForMeeting(Meeting $meeting): void
    {
        $tenant = $meeting->tenant;
        
        // Solo procesar si el tenant tiene jerarquías habilitadas y auto-asignación activa
        if ($tenant->hierarchy_mode === 'disabled' || !$tenant->auto_assign_hierarchy) {
            return;
        }

        // Solo procesar si la reunión tiene assigned_to_cedula
        if (!$meeting->assigned_to_cedula) {
            return;
        }

        $supervisorCedula = $meeting->assigned_to_cedula;
        
        // Obtener todos los asistentes de esta reunión
        $attendees = $meeting->attendees()->get();
        
        foreach ($attendees as $attendee) {
            // No crear jerarquía del supervisor consigo mismo
            if ($attendee->cedula === $supervisorCedula) {
                continue;
            }
            
            $this->createOrUpdateHierarchy(
                $tenant,
                $attendee->cedula,
                $attendee->nombres . ' ' . $attendee->apellidos,
                $attendee->email,
                $attendee->telefono,
                $supervisorCedula,
                $this->getSupervisorName($tenant, $supervisorCedula),
                $meeting->id
            );
        }
    }

    /**
     * Crear o actualizar relación jerárquica
     */
    protected function createOrUpdateHierarchy(
        Tenant $tenant,
        string $attendeeCedula,
        ?string $attendeeName,
        ?string $attendeeEmail,
        ?string $attendeePhone,
        string $supervisorCedula,
        ?string $supervisorName,
        int $meetingId
    ): void {
        DB::transaction(function () use (
            $tenant, $attendeeCedula, $attendeeName, $attendeeEmail, $attendeePhone,
            $supervisorCedula, $supervisorName, $meetingId
        ) {
            // Buscar relación existente
            $existing = AttendeeHierarchy::where('tenant_id', $tenant->id)
                ->where('attendee_cedula', $attendeeCedula)
                ->where('supervisor_cedula', $supervisorCedula)
                ->first();

            if ($existing) {
                // Actualizar relación existente
                $existing->update([
                    'relationship_strength' => $existing->relationship_strength + 1,
                    'last_interaction' => now()->toDateString(),
                    'attendee_name' => $attendeeName ?: $existing->attendee_name,
                    'attendee_email' => $attendeeEmail ?: $existing->attendee_email,
                    'attendee_phone' => $attendeePhone ?: $existing->attendee_phone,
                    'supervisor_name' => $supervisorName ?: $existing->supervisor_name,
                ]);
                
                Log::info('Updated attendee hierarchy relationship', [
                    'attendee' => $attendeeCedula,
                    'supervisor' => $supervisorCedula,
                    'strength' => $existing->relationship_strength
                ]);
            } else {
                // Crear nueva relación
                AttendeeHierarchy::create([
                    'tenant_id' => $tenant->id,
                    'attendee_cedula' => $attendeeCedula,
                    'attendee_name' => $attendeeName,
                    'attendee_email' => $attendeeEmail,
                    'attendee_phone' => $attendeePhone,
                    'supervisor_cedula' => $supervisorCedula,
                    'supervisor_name' => $supervisorName,
                    'relationship_strength' => 1,
                    'last_interaction' => now()->toDateString(),
                    'is_active' => true,
                    'created_by' => request()->user()->id ?? 1,
                ]);
                
                Log::info('Created new attendee hierarchy relationship', [
                    'attendee' => $attendeeCedula,
                    'supervisor' => $supervisorCedula
                ]);
            }

            // Determinar supervisor principal según configuración del tenant
            $this->determinePrimarySupervisor($tenant, $attendeeCedula);
        });
    }

    /**
     * Determinar supervisor principal según la configuración del tenant
     */
    protected function determinePrimarySupervisor(Tenant $tenant, string $attendeeCedula): void
    {
        $relationships = AttendeeHierarchy::where('tenant_id', $tenant->id)
            ->where('attendee_cedula', $attendeeCedula)
            ->active()
            ->get();

        if ($relationships->isEmpty()) {
            return;
        }

        // Limpiar primary flags existentes
        AttendeeHierarchy::where('tenant_id', $tenant->id)
            ->where('attendee_cedula', $attendeeCedula)
            ->update(['is_primary' => false]);

        $primarySupervisor = null;

        switch ($tenant->hierarchy_conflict_resolution) {
            case 'last_assignment':
                $primarySupervisor = $relationships->sortByDesc('last_interaction')->first();
                break;
                
            case 'most_active':
                $primarySupervisor = $relationships->sortByDesc('relationship_strength')->first();
                break;
                
            case 'manual_review':
                // No asignar automáticamente, requiere revisión manual
                Log::info('Manual review required for primary supervisor', [
                    'attendee' => $attendeeCedula,
                    'supervisors' => $relationships->pluck('supervisor_cedula')->toArray()
                ]);
                return;
        }

        if ($primarySupervisor) {
            $primarySupervisor->update(['is_primary' => true]);
            
            Log::info('Primary supervisor determined', [
                'attendee' => $attendeeCedula,
                'primary_supervisor' => $primarySupervisor->supervisor_cedula,
                'strategy' => $tenant->hierarchy_conflict_resolution
            ]);
        }
    }

    /**
     * Obtener nombre del supervisor buscando en asistentes
     */
    protected function getSupervisorName(Tenant $tenant, string $supervisorCedula): ?string
    {
        $attendee = MeetingAttendee::where('tenant_id', $tenant->id)
            ->where('cedula', $supervisorCedula)
            ->first();
            
        return $attendee ? $attendee->nombres . ' ' . $attendee->apellidos : null;
    }

    /**
     * Obtener árbol jerárquico de asistentes
     */
    public function getAttendeeTree(Tenant $tenant, ?string $rootCedula = null): array
    {
        $query = AttendeeHierarchy::where('tenant_id', $tenant->id)->active();
        
        if ($rootCedula) {
            $query->where('supervisor_cedula', $rootCedula);
        } else {
            // Obtener raíces (supervisores que no son supervisados por nadie)
            $supervisedCedulas = AttendeeHierarchy::where('tenant_id', $tenant->id)
                ->active()
                ->pluck('attendee_cedula')
                ->unique();
                
            $query->whereNotIn('supervisor_cedula', $supervisedCedulas);
        }

        $hierarchies = $query->get();
        
        return $hierarchies->map(function ($hierarchy) use ($tenant) {
            return [
                'cedula' => $hierarchy->attendee_cedula,
                'name' => $hierarchy->attendee_name,
                'email' => $hierarchy->attendee_email,
                'phone' => $hierarchy->attendee_phone,
                'supervisor_cedula' => $hierarchy->supervisor_cedula,
                'supervisor_name' => $hierarchy->supervisor_name,
                'relationship_strength' => $hierarchy->relationship_strength,
                'last_interaction' => $hierarchy->last_interaction?->toDateString(),
                'is_primary' => $hierarchy->is_primary,
                'context' => $hierarchy->context,
                'subordinates' => $this->getAttendeeTree($tenant, $hierarchy->attendee_cedula)
            ];
        })->toArray();
    }

    /**
     * Validar si el tenant requiere configuración de jerarquía antes de crear reuniones
     */
    public function validateHierarchyConfigRequired(Tenant $tenant): bool
    {
        if (!$tenant->require_hierarchy_config) {
            return true;
        }

        // Verificar que el tenant tenga configuración válida
        if ($tenant->hierarchy_mode === 'disabled') {
            return false;
        }

        return true;
    }
}