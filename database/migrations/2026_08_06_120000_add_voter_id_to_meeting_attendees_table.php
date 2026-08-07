<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Liga el asistente a la persona (Spec 0022).
 *
 * `meeting_attendees` guardaba el evento de check-in, no a quien asiste: la
 * cédula era texto suelto, así que no había forma de deduplicar ni de saber si
 * alguien ya había venido antes. `voter_id` es esa clave de persona.
 *
 * Nullable a propósito: hay asistencia histórica de gente que nunca entró a
 * `voters`, y el backfill no la inventa (ver `down()` y el comentario del
 * relleno).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->foreignId('voter_id')
                ->nullable()
                ->after('meeting_id')
                ->constrained('voters')
                ->nullOnDelete();

            // El stat de nuevos vs recurrentes filtra por tenant y agrupa por
            // persona.
            $table->index(['tenant_id', 'voter_id']);
        });

        $this->rellenarPorCedula();
    }

    public function down(): void
    {
        Schema::table('meeting_attendees', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'voter_id']);

            // SQLite no soporta DROP CONSTRAINT: allí la FK desaparece al
            // reconstruirse la tabla junto con la columna.
            if (DB::getDriverName() !== 'sqlite') {
                $table->dropForeign(['voter_id']);
            }

            $table->dropColumn('voter_id');
        });
    }

    /**
     * Liga la asistencia existente al votante que ya tenga esa cédula, dentro
     * del mismo tenant. Sin votante → se queda en `null`: crear votantes en
     * masa a partir de asistencia histórica metería en la base electoral gente
     * que nadie verificó.
     *
     * Idempotente: solo toca filas con `voter_id` nulo, y comparar cédulas
     * normalizadas es estable entre corridas.
     */
    private function rellenarPorCedula(): void
    {
        $votantesPorTenant = [];

        DB::table('meeting_attendees')
            ->select('id', 'tenant_id', 'cedula')
            ->whereNull('voter_id')
            ->whereNotNull('cedula')
            ->orderBy('id')
            ->chunkById(500, function ($filas) use (&$votantesPorTenant) {
                foreach ($filas as $fila) {
                    if (! isset($votantesPorTenant[$fila->tenant_id])) {
                        $votantesPorTenant[$fila->tenant_id] = $this->votantesDelTenant($fila->tenant_id);
                    }

                    $clave = $this->normalizar($fila->cedula);
                    $voterId = $votantesPorTenant[$fila->tenant_id][$clave] ?? null;

                    if ($voterId) {
                        DB::table('meeting_attendees')
                            ->where('id', $fila->id)
                            ->update(['voter_id' => $voterId]);
                    }
                }
            });
    }

    /**
     * @return array<string, int> cédula normalizada → id del votante
     */
    private function votantesDelTenant(?int $tenantId): array
    {
        if (! $tenantId) {
            return [];
        }

        return DB::table('voters')
            ->select('id', 'cedula')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->get()
            ->mapWithKeys(fn ($voter) => [$this->normalizar($voter->cedula) => $voter->id])
            ->all();
    }

    /**
     * Misma normalización que `AttendanceService::normalizarCedula()`: sin
     * puntos, espacios ni guiones. "71.000.001" y "71000001" son la misma
     * persona.
     */
    private function normalizar(?string $cedula): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $cedula) ?? '');
    }
};
