<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Voter;

/**
 * Busca los datos de una persona por cédula para autocompletar formularios.
 *
 * Tres fuentes, en este orden:
 *
 * 1. **`voters`** — la base de la propia campaña. Va primero porque es la que
 *    alguien mantiene: si la persona ya está ahí, ese dato gana sobre cualquier
 *    registro externo, que puede estar desactualizado (Spec 0022).
 * 2. **PISAMI** — registro externo del municipio.
 * 3. **`leads`** — captación previa.
 *
 * Las dos fuentes locales se apoyan en `TenantScope`, así que **el llamador debe
 * haber fijado `current_tenant_id`** antes de invocar `verify()`: en las rutas
 * autenticadas lo hace `EnsureTenant`, y en la ruta pública del QR lo fija el
 * controlador a partir de la reunión (Spec 0026).
 */
class DocumentVerificationService
{
    public function __construct(private PisamiService $pisami) {}

    /**
     * @return array{data: array<string, mixed>, source: string}|null
     */
    public function verify(string $cedula): ?array
    {
        if ($votante = $this->buscarVotante($cedula)) {
            return ['data' => $this->datosDelVotante($votante), 'source' => 'voters'];
        }

        if ($datos = $this->pisami->verifyDocument($cedula)) {
            return ['data' => $datos, 'source' => 'pisami'];
        }

        $lead = Lead::where('cedula', $cedula)->first();

        if (! $lead) {
            return null;
        }

        return [
            'source' => 'leads',
            'data' => [
                'cedula' => $lead->cedula,
                'nombres' => trim(($lead->nombre1 ?? '').' '.($lead->nombre2 ?? '')),
                'apellidos' => trim(($lead->apellido1 ?? '').' '.($lead->apellido2 ?? '')),
                'nombre_completo' => $lead->full_name,
                'fecha_nacimiento' => $lead->fecha_nacimiento?->format('Y-m-d'),
                'telefono' => $lead->telefono,
                'email' => $lead->email,
                'direccion' => $lead->direccion,
                'barrio' => $lead->barrio_otro,
                'departamento_votacion' => $lead->departamento_votacion,
                'municipio_votacion' => $lead->municipio_votacion,
                'puesto_votacion' => $lead->puesto_votacion,
                'zona_votacion' => $lead->zona_votacion,
                'mesa_votacion' => $lead->mesa_votacion,
                'direccion_votacion' => $lead->direccion_votacion,
                'locality_name' => $lead->locality_name,
                'latitud' => $lead->latitud,
                'longitud' => $lead->longitud,
            ],
        ];
    }

    /**
     * La cédula puede venir con puntos del formulario, y la base puede tenerla
     * guardada de las dos formas: se prueban ambas.
     */
    private function buscarVotante(string $cedula): ?Voter
    {
        $normalizada = AttendanceService::normalizarCedula($cedula);

        $candidatas = array_values(array_unique(array_filter([$normalizada, trim($cedula)])));

        return $candidatas === [] ? null : Voter::whereIn('cedula', $candidatas)->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function datosDelVotante(Voter $votante): array
    {
        return [
            'cedula' => $votante->cedula,
            'nombres' => $votante->nombres,
            'apellidos' => $votante->apellidos,
            'nombre_completo' => $votante->full_name,
            'telefono' => $votante->telefono,
            'email' => $votante->email,
            'direccion' => $votante->direccion,
            'departamento_votacion' => $votante->departamento_votacion,
            'municipio_votacion' => $votante->municipio_votacion,
            'puesto_votacion' => $votante->puesto_votacion,
            'mesa_votacion' => $votante->mesa_votacion,
            'direccion_votacion' => $votante->direccion_votacion,
        ];
    }

    /**
     * Recorte para respuestas públicas: solo lo que el formulario del QR
     * necesita rellenar. La dirección y el puesto de votación son PII que no
     * debe viajar por una ruta sin autenticación.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    public static function soloContacto(array $datos): array
    {
        return [
            'nombres' => $datos['nombres'] ?? null,
            'apellidos' => $datos['apellidos'] ?? null,
            'telefono' => $datos['telefono'] ?? null,
            'email' => $datos['email'] ?? null,
        ];
    }
}
