<?php

namespace App\Observers;

use App\Models\MeetingAttendee;
use App\Services\AttendanceService;
use Illuminate\Support\Facades\Log;

/**
 * Cada asistente registrado alimenta la base electoral.
 *
 * La lógica vive en `AttendanceService` (Spec 0022): así el check-in público y
 * el alta manual desde el panel crean la misma persona con las mismas reglas.
 * Antes este observer tenía su propia copia, que comparaba cédulas sin
 * normalizar —"71.000.001" y "71000001" acababan como dos votantes— y no dejaba
 * rastro del vínculo.
 *
 * El `try/catch` se queda: la asistencia es el hecho principal y no puede
 * perderse porque falle la sincronización. Lo que cambia es que ahora el error
 * es la excepción y no la norma.
 */
class MeetingAttendeeObserver
{
    public function __construct(private AttendanceService $asistencia) {}

    public function created(MeetingAttendee $meetingAttendee): void
    {
        $this->sincronizar($meetingAttendee);
    }

    public function updated(MeetingAttendee $meetingAttendee): void
    {
        $this->sincronizar($meetingAttendee);
    }

    /**
     * Al restaurar un asistente se vuelve a ligar: pudo cambiar el votante.
     */
    public function restored(MeetingAttendee $meetingAttendee): void
    {
        $this->sincronizar($meetingAttendee);
    }

    /**
     * Borrar la asistencia no borra a la persona: el votante es el registro
     * oficial y puede tener otras asistencias.
     */
    public function deleted(MeetingAttendee $meetingAttendee): void {}

    public function forceDeleted(MeetingAttendee $meetingAttendee): void {}

    private function sincronizar(MeetingAttendee $asistente): void
    {
        try {
            $this->asistencia->vincularVotante($asistente);
        } catch (\Throwable $e) {
            Log::error('No se pudo sincronizar el asistente con la base de votantes', [
                'attendee_id' => $asistente->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
