<?php

namespace App\Services;

use App\Models\Lead;

/**
 * Busca los datos de una persona por cédula para autocompletar formularios.
 *
 * Primero PISAMI (registro externo del municipio) y, si no responde, la tabla
 * local de leads. La consulta local se apoya en `TenantScope`, así que **el
 * llamador debe haber fijado `current_tenant_id`** antes de invocar `verify()`:
 * en las rutas autenticadas lo hace `EnsureTenant`, y en la ruta pública del QR
 * lo fija el controlador a partir de la reunión (Spec 0026).
 */
class DocumentVerificationService
{
    public function __construct(private PisamiService $pisami) {}

    /**
     * @return array{data: array<string, mixed>, source: string}|null
     */
    public function verify(string $cedula): ?array
    {
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
