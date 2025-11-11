<?php

namespace App\Observers;

use App\Models\MeetingAttendee;
use App\Models\Voter;
use Illuminate\Support\Facades\Log;

class MeetingAttendeeObserver
{
    /**
     * Handle the MeetingAttendee "created" event.
     * Sincroniza automáticamente el asistente a la tabla voters
     */
    public function created(MeetingAttendee $meetingAttendee): void
    {
        $this->syncToVoters($meetingAttendee);
    }

    /**
     * Handle the MeetingAttendee "updated" event.
     * Actualiza el registro en voters si existe
     */
    public function updated(MeetingAttendee $meetingAttendee): void
    {
        $this->syncToVoters($meetingAttendee, true);
    }

    /**
     * Sincroniza el asistente a la tabla voters
     */
    protected function syncToVoters(MeetingAttendee $attendee, bool $isUpdate = false): void
    {
        try {
            // Buscar voter existente por cédula y tenant
            $voter = Voter::withoutGlobalScopes()
                ->where('tenant_id', $attendee->tenant_id)
                ->where('cedula', $attendee->cedula)
                ->first();

            if ($voter) {
                // El voter ya existe - actualizar solo si hay información nueva
                $this->updateExistingVoter($voter, $attendee);
            } else {
                // Crear nuevo voter
                $this->createNewVoter($attendee);
            }
        } catch (\Exception $e) {
            Log::error('Error syncing attendee to voters', [
                'attendee_id' => $attendee->id,
                'cedula' => $attendee->cedula,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Crea un nuevo voter a partir del asistente
     */
    protected function createNewVoter(MeetingAttendee $attendee): void
    {
        Voter::create([
            'tenant_id' => $attendee->tenant_id,
            'cedula' => $attendee->cedula,
            'nombres' => $attendee->nombres,
            'apellidos' => $attendee->apellidos,
            'email' => $attendee->email,
            'telefono' => $attendee->telefono,
            'direccion' => $attendee->direccion,
            'barrio_id' => $attendee->barrio_id,
            'meeting_id' => $attendee->meeting_id, // Primera reunión donde se registró
            'created_by' => $attendee->created_by,
        ]);

        Log::info('New voter created from meeting attendee', [
            'cedula' => $attendee->cedula,
            'nombres' => $attendee->nombres,
            'apellidos' => $attendee->apellidos,
            'meeting_id' => $attendee->meeting_id,
        ]);
    }

    /**
     * Actualiza un voter existente con nueva información del asistente
     * Solo actualiza campos que estén vacíos en voter o que sean más recientes
     */
    protected function updateExistingVoter(Voter $voter, MeetingAttendee $attendee): void
    {
        $updated = false;
        $changes = [];

        // Actualizar campos solo si están vacíos en voter y tienen valor en attendee
        if (empty($voter->email) && !empty($attendee->email)) {
            $voter->email = $attendee->email;
            $changes[] = 'email';
            $updated = true;
        }

        if (empty($voter->telefono) && !empty($attendee->telefono)) {
            $voter->telefono = $attendee->telefono;
            $changes[] = 'telefono';
            $updated = true;
        }

        if (empty($voter->direccion) && !empty($attendee->direccion)) {
            $voter->direccion = $attendee->direccion;
            $changes[] = 'direccion';
            $updated = true;
        }

        if (empty($voter->barrio_id) && !empty($attendee->barrio_id)) {
            $voter->barrio_id = $attendee->barrio_id;
            $changes[] = 'barrio_id';
            $updated = true;
        }

        // Si el voter ya tenía datos pero son diferentes a los del nuevo attendee,
        // marcar que tiene múltiples registros con datos diferentes
        $hasConflicts = false;

        if (!empty($voter->email) && !empty($attendee->email) && $voter->email !== $attendee->email) {
            $hasConflicts = true;
        }

        if (!empty($voter->telefono) && !empty($attendee->telefono) && $voter->telefono !== $attendee->telefono) {
            $hasConflicts = true;
        }

        if (!empty($voter->barrio_id) && !empty($attendee->barrio_id) && $voter->barrio_id !== $attendee->barrio_id) {
            $hasConflicts = true;
        }

        if ($hasConflicts && !$voter->has_multiple_records) {
            $voter->has_multiple_records = true;
            $changes[] = 'has_multiple_records';
            $updated = true;
        }

        if ($updated) {
            $voter->save();

            Log::info('Voter updated from meeting attendee', [
                'voter_id' => $voter->id,
                'cedula' => $voter->cedula,
                'changes' => $changes,
                'has_conflicts' => $hasConflicts,
            ]);
        }
    }

    /**
     * Handle the MeetingAttendee "deleted" event.
     */
    public function deleted(MeetingAttendee $meetingAttendee): void
    {
        // No eliminamos el voter cuando se elimina un asistente
        // El voter es el registro oficial y puede tener múltiples asistencias
    }

    /**
     * Handle the MeetingAttendee "restored" event.
     */
    public function restored(MeetingAttendee $meetingAttendee): void
    {
        // Resincronizar al restaurar
        $this->syncToVoters($meetingAttendee);
    }

    /**
     * Handle the MeetingAttendee "force deleted" event.
     */
    public function forceDeleted(MeetingAttendee $meetingAttendee): void
    {
        // No hacer nada, el voter se mantiene
    }
}
