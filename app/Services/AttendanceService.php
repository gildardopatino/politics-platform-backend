<?php

namespace App\Services;

use App\Models\Meeting;
use App\Models\MeetingAttendee;
use App\Models\TipoVotante;
use App\Models\Voter;
use App\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * La asistencia es de una PERSONA, no de un formulario (Spec 0022).
 *
 * Aquí vive todo lo que convierte un check-in en identidad: normalizar la
 * cédula, buscar o crear el Votante, ligar al asistente, completar lo que falte
 * y no duplicar. El controlador solo resuelve la reunión por su QR y delega.
 *
 * **El ámbito lo fija la reunión.** El check-in es público y no hay usuario que
 * establezca el tenant, así que el servicio enlaza `current_tenant_id` con el
 * de la reunión antes de tocar nada: de ahí en adelante `TenantScope` filtra y
 * una cédula de otra campaña es, sencillamente, otra persona (Spec 0026).
 */
class AttendanceService
{
    public function __construct(private DocumentVerificationService $verificador) {}

    /**
     * Deja constancia de que esta persona asistió a esta reunión.
     *
     * Segundo check-in de la misma cédula en la misma reunión → actualiza la
     * fila que ya existe; entre reuniones distintas → dos asistencias de un
     * mismo votante.
     *
     * @param  array<string, mixed>  $datos  payload validado del formulario
     */
    public function registrarCheckIn(Meeting $meeting, array $datos, ?int $createdBy = null): MeetingAttendee
    {
        $cedula = self::normalizarCedula($datos['cedula'] ?? null);

        return $this->enElAmbitoDe($meeting, fn () => DB::transaction(function () use ($meeting, $datos, $cedula, $createdBy) {
            $votante = $this->resolverVotante($meeting, $cedula, $datos, $createdBy);

            $atributos = $this->atributosDelAsistente($datos, $cedula, $votante, $createdBy);

            $existente = $meeting->attendees()
                ->where(function ($query) use ($cedula, $datos, $votante) {
                    $query->whereIn('cedula', $this->cedulasEquivalentes($cedula, $datos));

                    // Cubre la asistencia previa a esta spec, guardada con la
                    // cédula tal cual la tecleó quien la capturó.
                    if ($votante) {
                        $query->orWhere('voter_id', $votante->id);
                    }
                })
                ->first();

            if ($existente) {
                $existente->fill($atributos)->save();

                return $existente->refresh();
            }

            return $meeting->attendees()->create($atributos);
        }));
    }

    /**
     * Ejecuta la operación con `current_tenant_id` puesto en el tenant de la
     * reunión y lo deja como estaba al salir.
     *
     * El check-in es público: no hay usuario que establezca el ámbito, y sin él
     * `TenantScope` no filtra —que es exactamente cómo se abrió la fuga de la
     * Spec 0026—. El QR es la credencial que lo fija. Se restaura al terminar
     * porque el contenedor es global: dejarlo puesto convertiría el ámbito de
     * una petición en el de la siguiente.
     */
    private function enElAmbitoDe(Meeting $meeting, callable $operacion): mixed
    {
        $habia = app()->bound('current_tenant_id');
        $anterior = $habia ? app('current_tenant_id') : null;

        app()->instance('current_tenant_id', $meeting->tenant_id);

        try {
            return $operacion();
        } finally {
            if ($habia) {
                app()->instance('current_tenant_id', $anterior);
            } else {
                app()->forgetInstance('current_tenant_id');
            }
        }
    }

    /**
     * Liga un asistente a su votante, creándolo si hace falta.
     *
     * Lo usa `MeetingAttendeeObserver` para que **cualquier** vía de alta de
     * asistentes —el check-in público y el alta manual desde el panel— alimente
     * la base electoral con las mismas reglas. No consulta el recurso en línea:
     * un observer no debe salir a la red en cada guardado.
     */
    public function vincularVotante(MeetingAttendee $asistente): ?Voter
    {
        if (! $asistente->tenant_id || ! $asistente->cedula) {
            return null;
        }

        $cedula = self::normalizarCedula($asistente->cedula);

        $votante = $this->buscarVotante($asistente->tenant_id, $cedula, $asistente->cedula)
            ?? $this->crearVotante($asistente->tenant_id, $cedula, [
                'nombres' => $asistente->nombres,
                'apellidos' => $asistente->apellidos,
                'telefono' => $asistente->telefono,
                'email' => $asistente->email,
                'direccion' => $asistente->direccion,
                'barrio_id' => $asistente->barrio_id,
            ], $asistente->meeting_id, $asistente->created_by);

        if (! $votante) {
            return null;
        }

        $this->completarVotante($votante, $asistente->only([
            'telefono', 'email', 'direccion', 'barrio_id',
        ]));

        if ($asistente->voter_id !== $votante->id) {
            $asistente->voter_id = $votante->id;
            // Sin eventos: este guardado es consecuencia del anterior, y
            // dispararlos otra vez sería un bucle.
            $asistente->saveQuietly();
        }

        return $votante;
    }

    /**
     * Cuánta gente NUEVA llegó a esta reunión.
     *
     * **Nuevo** = esta reunión es la primera asistencia de esa persona en el
     * tenant, ordenando por `checked_in_at` y, a igualdad, por id. No es "no
     * estaba en `voters`": alguien puede llevar años en la base electoral y
     * pisar su primera reunión hoy, y eso es justo lo que mide la métrica.
     *
     * La persona se identifica por cédula normalizada, no por `voter_id`: la
     * asistencia anterior a esta spec puede no tener votante ligado y aun así
     * cuenta como visita previa.
     *
     * @return array<string, int>
     */
    public function estadisticasDeReunion(Meeting $meeting): array
    {
        $deLaReunion = $this->asistenciaDelTenant($meeting->tenant_id)
            ->where('meeting_id', $meeting->id)
            ->get();

        $personas = $deLaReunion
            ->map(fn (MeetingAttendee $fila) => self::normalizarCedula($fila->cedula))
            ->filter()
            ->unique();

        if ($personas->isEmpty()) {
            return [
                'meeting_id' => $meeting->id,
                'total_check_ins' => $deLaReunion->count(),
                'unique_attendees' => 0,
                'new_attendees' => 0,
                'recurring_attendees' => 0,
                'linked_to_voter' => 0,
            ];
        }

        // Todo el historial de esas personas en la campaña, para saber dónde
        // estrenó cada una.
        $primeraVisita = $this->asistenciaDelTenant($meeting->tenant_id)
            ->get()
            ->groupBy(fn (MeetingAttendee $fila) => self::normalizarCedula($fila->cedula))
            ->map(fn ($filas) => $filas->sort(
                fn ($a, $b) => $this->ordenDeVisita($a) <=> $this->ordenDeVisita($b)
            )->first());

        $nuevos = $personas->filter(
            fn (string $cedula) => $primeraVisita[$cedula]?->meeting_id === $meeting->id
        );

        return [
            'meeting_id' => $meeting->id,
            'total_check_ins' => $deLaReunion->count(),
            'unique_attendees' => $personas->count(),
            'new_attendees' => $nuevos->count(),
            'recurring_attendees' => $personas->count() - $nuevos->count(),
            'linked_to_voter' => $deLaReunion->whereNotNull('voter_id')->count(),
        ];
    }

    /**
     * Sin puntos, espacios ni guiones: "71.000.001", "71 000 001" y "71000001"
     * son la misma persona. En mayúsculas por los documentos con letra.
     */
    public static function normalizarCedula(?string $cedula): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $cedula) ?? '');
    }

    // ------------------------------------------------------------------

    /**
     * Asistencia acotada al tenant de la reunión, sin depender de que el
     * llamador haya enlazado `current_tenant_id`. No cruza tenants: lo fija.
     */
    private function asistenciaDelTenant(int $tenantId): Builder
    {
        return MeetingAttendee::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId);
    }

    /**
     * Cuándo pisó la persona esa reunión. `checked_in_at` es lo que importa;
     * `created_at` cubre las filas dadas de alta sin check-in, y el id desempata
     * para que el resultado no dependa del orden en que vuelva la consulta.
     *
     * @return array{int, int}
     */
    private function ordenDeVisita(MeetingAttendee $fila): array
    {
        return [
            ($fila->checked_in_at ?? $fila->created_at)?->getTimestamp() ?? 0,
            $fila->id,
        ];
    }

    private function resolverVotante(Meeting $meeting, string $cedula, array $datos, ?int $createdBy): ?Voter
    {
        $votante = $this->buscarVotante($meeting->tenant_id, $cedula, $datos['cedula'] ?? null);

        if ($votante) {
            return $votante;
        }

        return $this->crearVotante(
            $meeting->tenant_id,
            $cedula,
            // El formulario manda; el recurso en línea solo llena huecos.
            array_merge($this->consultarRecursoEnLinea($cedula), array_filter([
                'nombres' => $datos['nombres'] ?? null,
                'apellidos' => $datos['apellidos'] ?? null,
                'telefono' => $datos['telefono'] ?? null,
                'email' => $datos['email'] ?? null,
                'barrio_id' => $datos['barrio_id'] ?? null,
            ], fn ($valor) => $valor !== null && $valor !== '')),
            $meeting->id,
            $createdBy
        );
    }

    private function buscarVotante(int $tenantId, string $cedula, ?string $original): ?Voter
    {
        return Voter::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereIn('cedula', $this->cedulasEquivalentes($cedula, ['cedula' => $original]))
            ->first();
    }

    /**
     * La normalizada y la que llegó: la base puede tener cédulas capturadas con
     * puntos desde antes de esta spec.
     *
     * @return array<int, string>
     */
    private function cedulasEquivalentes(string $cedula, array $datos): array
    {
        return array_values(array_unique(array_filter([
            $cedula,
            trim((string) ($datos['cedula'] ?? '')),
        ])));
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function crearVotante(int $tenantId, string $cedula, array $datos, ?int $meetingId, ?int $createdBy): ?Voter
    {
        if ($cedula === '') {
            return null;
        }

        try {
            return Voter::create([
                'tenant_id' => $tenantId,
                'cedula' => $cedula,
                'nombres' => $datos['nombres'] ?? '',
                'apellidos' => $datos['apellidos'] ?? '',
                'telefono' => $datos['telefono'] ?? null,
                'email' => $datos['email'] ?? null,
                'direccion' => $datos['direccion'] ?? null,
                'barrio_id' => $datos['barrio_id'] ?? null,
                // Primera reunión donde apareció esta persona.
                'meeting_id' => $meetingId,
                'tipo_votante_id' => $this->tipoVotantePorDefecto(),
                'created_by' => $createdBy,
            ]);
        } catch (QueryException $e) {
            // `voters` tiene UNIQUE(tenant_id, cedula): si dos check-in de la
            // misma persona entran a la vez, uno pierde la carrera y se queda
            // con el votante que creó el otro.
            $votante = $this->buscarVotante($tenantId, $cedula, null);

            if (! $votante) {
                Log::error('No se pudo crear el votante desde la asistencia', [
                    'tenant_id' => $tenantId,
                    'error' => $e->getMessage(),
                ]);
            }

            return $votante;
        }
    }

    /**
     * Consulta el recurso en línea (PISAMI y, si no responde, los leads del
     * tenant). Best-effort: si se cae, el check-in sigue con lo del formulario.
     *
     * @return array<string, mixed>
     */
    private function consultarRecursoEnLinea(string $cedula): array
    {
        if ($cedula === '') {
            return [];
        }

        try {
            $resultado = $this->verificador->verify($cedula);
        } catch (\Throwable $e) {
            Log::warning('El recurso en línea no respondió durante el check-in', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return array_filter([
            'nombres' => $resultado['data']['nombres'] ?? null,
            'apellidos' => $resultado['data']['apellidos'] ?? null,
            'telefono' => $resultado['data']['telefono'] ?? null,
            'email' => $resultado['data']['email'] ?? null,
            'direccion' => $resultado['data']['direccion'] ?? null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /**
     * Lo que la persona escribió en el formulario manda; del votante solo se
     * toma lo que el formulario dejó en blanco.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function atributosDelAsistente(array $datos, string $cedula, ?Voter $votante, ?int $createdBy): array
    {
        $atributos = array_merge($datos, [
            'cedula' => $cedula,
            'voter_id' => $votante?->id,
            'checked_in' => true,
            'checked_in_at' => now(),
        ]);

        if ($createdBy) {
            $atributos['created_by'] = $createdBy;
        }

        foreach (['nombres', 'apellidos', 'telefono', 'email', 'direccion'] as $campo) {
            if (blank($atributos[$campo] ?? null) && filled($votante?->{$campo})) {
                $atributos[$campo] = $votante->{$campo};
            }
        }

        return array_filter(
            $atributos,
            fn ($valor, $campo) => $valor !== null || in_array($campo, ['voter_id'], true),
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Rellena los huecos del votante con lo que trajo la asistencia y marca el
     * conflicto cuando los datos no coinciden: un formulario público no
     * reescribe lo que alguien ya curó, pero deja constancia para revisarlo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function completarVotante(Voter $votante, array $datos): void
    {
        $conflicto = false;

        foreach (['telefono', 'email', 'direccion', 'barrio_id'] as $campo) {
            $nuevo = $datos[$campo] ?? null;

            if (blank($nuevo)) {
                continue;
            }

            if (blank($votante->{$campo})) {
                $votante->{$campo} = $nuevo;
            } elseif ($votante->{$campo} != $nuevo) {
                $conflicto = true;
            }
        }

        if ($conflicto && ! $votante->has_multiple_records) {
            $votante->has_multiple_records = true;
        }

        if ($votante->isDirty()) {
            $votante->save();
        }
    }

    /**
     * `voters.tipo_votante_id` es NOT NULL con FK al catálogo. Se resuelve por
     * descripción y no por el id 1 del DEFAULT: si el catálogo no está sembrado
     * —o le cambian el orden— la inserción reventaría, y el observer que hacía
     * esto antes se tragaba ese error en silencio.
     */
    private function tipoVotantePorDefecto(): int
    {
        return TipoVotante::firstOrCreate(['descripcion' => 'Elector'])->id;
    }
}
