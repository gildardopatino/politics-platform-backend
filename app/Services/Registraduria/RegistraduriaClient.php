<?php

namespace App\Services\Registraduria;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente del servicio propio de consulta a la Registraduría (Spec 0091).
 *
 * Invierte el flujo de la Spec 0030: ya no hay n8n pidiendo pendientes y
 * escribiendo por webhook; es Laravel quien llama **de salida** al servicio
 * Python (`platform-politics-registraduria`, Parte A) cuando le falta el puesto
 * de un votante.
 *
 * Sin `services.registraduria.url` configurada el cliente **no sale a la red**:
 * devuelve un fallo. Es el estado por defecto de una instalación que todavía no
 * levantó el servicio, y el de las pruebas.
 */
class RegistraduriaClient
{
    private const RUTA = '/api/consultar';

    public function consultar(string $cedula): ResultadoConsulta
    {
        $cedula = Cedula::normalizar($cedula);

        if ($cedula === '') {
            return ResultadoConsulta::fallo('La cédula no tiene dígitos.');
        }

        $base = rtrim((string) config('services.registraduria.url'), '/');

        if ($base === '') {
            return ResultadoConsulta::fallo('El servicio de Registraduría no está configurado.');
        }

        try {
            $respuesta = Http::acceptJson()
                ->withToken((string) config('services.registraduria.token'))
                ->timeout((int) config('services.registraduria.timeout', 300))
                ->post($base.self::RUTA, ['documento' => $cedula]);
        } catch (Throwable $e) {
            // El mensaje de cURL puede traer la URL con el cuerpo; se sanea por
            // si acaso antes de que llegue a un log (Art. VII).
            return $this->fallar('No se pudo contactar el servicio de Registraduría.', $e->getMessage(), $cedula);
        }

        $cuerpo = $respuesta->json();
        $estado = is_array($cuerpo) ? ($cuerpo['estado'] ?? null) : null;
        $via = is_array($cuerpo) ? ($cuerpo['via'] ?? null) : null;

        if (! $respuesta->successful()) {
            return $this->fallar(
                'El servicio de Registraduría respondió con error.',
                sprintf('HTTP %d, estado: %s', $respuesta->status(), $estado ?? 'desconocido'),
                $cedula,
            );
        }

        if ($estado === ResultadoConsulta::ENCONTRADO) {
            $datos = is_array($cuerpo['datos'] ?? null) ? $cuerpo['datos'] : [];

            return ResultadoConsulta::encontrado($datos, is_string($via) ? $via : null);
        }

        if ($estado === ResultadoConsulta::NO_ENCONTRADO) {
            return ResultadoConsulta::noEncontrado(is_string($via) ? $via : null);
        }

        return $this->fallar(
            'El servicio de Registraduría devolvió un estado inesperado.',
            sprintf('estado: %s', $estado ?? 'ausente'),
            $cedula,
        );
    }

    /**
     * Registra el fallo (con la cédula enmascarada) y lo devuelve tipado.
     */
    private function fallar(string $mensaje, string $detalle, string $cedula): ResultadoConsulta
    {
        $detalle = Cedula::sanear($detalle, $cedula);

        Log::warning($mensaje, [
            'cedula' => Cedula::enmascarar($cedula),
            'detalle' => $detalle,
        ]);

        return ResultadoConsulta::fallo($mensaje.' '.$detalle);
    }
}
