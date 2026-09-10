<?php

namespace App\Services\Registraduria;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Habla con el servicio de Registraduría (Spec 0091, Parte A).
 *
 * Es el único sitio que conoce el contrato del servicio Python: su URL, su
 * token, y que responde `{ estado, via, datos: [ { NUIP, DEPARTAMENTO, ... } ] }`
 * con las claves en mayúsculas. Hacia adentro devuelve un {@see ResultadoConsulta}
 * con los datos ya traducidos a nombres de columna de `voters`, para que
 * `RegistraduriaSyncService` no tenga que saber cómo escribe el scraper.
 *
 * **La cédula no va a los logs en claro** (Art. VII): se enmascara igual que en
 * el servicio Python (`14****37`).
 */
class RegistraduriaClient
{
    /**
     * Sin URL configurada la integración está apagada: ni se encola ni se sale
     * a la red. Lo consultan también el Job y el comando antes de encolar, para
     * no llenar la cola de trabajos que no pueden hacer nada.
     */
    public function configurado(): bool
    {
        return filled(config('services.registraduria.url'));
    }

    public function consultar(string $cedula): ResultadoConsulta
    {
        $documento = self::normalizar($cedula);

        if ($documento === '') {
            return ResultadoConsulta::fallo('documento_vacio');
        }

        if (! $this->configurado()) {
            return ResultadoConsulta::fallo('servicio_no_configurado');
        }

        try {
            $respuesta = Http::acceptJson()
                ->withToken((string) config('services.registraduria.token'))
                ->timeout((int) config('services.registraduria.timeout', 320))
                ->post($this->url().'/api/consultar', ['documento' => $documento]);
        } catch (ConnectionException $e) {
            // Servicio caído o techo de tiempo agotado. El Job lo reintenta.
            Log::warning('El servicio de Registraduría no respondió', [
                'documento' => self::enmascarar($documento),
                'error' => $e->getMessage(),
            ]);

            return ResultadoConsulta::fallo('sin_conexion');
        }

        $estado = (string) $respuesta->json('estado', '');
        $via = $respuesta->json('via');

        if ($respuesta->successful() && $estado === 'encontrado') {
            return $this->conDatos($respuesta->json('datos'), $via, $documento);
        }

        if ($respuesta->successful() && $estado === 'no_encontrado') {
            return ResultadoConsulta::noEncontrado($via);
        }

        // 401 (token mal), 502 (`captcha_fallido`/`error`), 504 (techo de
        // tiempo) y cualquier otra cosa. El estado del cuerpo dice más que el
        // código cuando viene; si no, queda el código.
        Log::warning('El servicio de Registraduría devolvió un resultado no utilizable', [
            'documento' => self::enmascarar($documento),
            'http' => $respuesta->status(),
            'estado' => $estado !== '' ? $estado : null,
        ]);

        return ResultadoConsulta::fallo($estado !== '' ? $estado : 'http_'.$respuesta->status());
    }

    /**
     * `encontrado` con `datos: []` no es un puesto: es el servicio
     * contradiciéndose. Se trata como fallo para que se reintente en vez de
     * escribir nulos encima de un votante.
     *
     * @param  mixed  $datos
     */
    private function conDatos($datos, ?string $via, string $documento): ResultadoConsulta
    {
        $fila = is_array($datos) ? ($datos[0] ?? null) : null;

        if (! is_array($fila) || blank($fila)) {
            Log::warning('El servicio de Registraduría dijo «encontrado» sin datos', [
                'documento' => self::enmascarar($documento),
            ]);

            return ResultadoConsulta::fallo('respuesta_sin_datos');
        }

        return ResultadoConsulta::encontrado(self::traducir($fila), is_string($via) ? $via : null);
    }

    /**
     * Del contrato del servicio a las columnas de `voters`.
     *
     * `NUIP` no se traduce a propósito: el votante ya se identifica por su fila,
     * y volver a escribirle la cédula con lo que devolvió un scraper sería
     * dejarle a un servicio externo la llave de la identidad.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private static function traducir(array $fila): array
    {
        return [
            'departamento_votacion' => self::texto($fila['DEPARTAMENTO'] ?? null),
            'municipio_votacion' => self::texto($fila['MUNICIPIO'] ?? null),
            'puesto_votacion' => self::texto($fila['PUESTO'] ?? null),
            'direccion_votacion' => self::texto($fila['DIRECCION'] ?? null),
            'mesa_votacion' => self::texto($fila['MESA'] ?? null),
        ];
    }

    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $limpio = trim((string) $valor);

        return $limpio === '' ? null : $limpio;
    }

    private function url(): string
    {
        return rtrim((string) config('services.registraduria.url'), '/');
    }

    /**
     * Solo dígitos: `1.439.873-7` y `14398737` son la misma cédula, y el
     * servicio la quiere ya normalizada.
     */
    public static function normalizar(?string $cedula): string
    {
        return (string) preg_replace('/\D+/', '', (string) $cedula);
    }

    /**
     * `14398737` → `14****37`. Suficiente para seguir un caso en los logs sin
     * dejar la cédula escrita (Art. VII). Un documento muy corto se tapa entero.
     */
    public static function enmascarar(string $documento): string
    {
        $largo = strlen($documento);

        if ($largo <= 4) {
            return str_repeat('*', $largo);
        }

        return substr($documento, 0, 2).str_repeat('*', $largo - 4).substr($documento, -2);
    }
}
