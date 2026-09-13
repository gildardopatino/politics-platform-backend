<?php

namespace App\Services\Registraduria;

/**
 * Lo que devolvió una consulta a la Registraduría (Spec 0091).
 *
 * Existe para que quien llama no tenga que interpretar códigos HTTP ni el
 * `estado` que manda el servicio Python: los tres desenlaces se tratan distinto
 * y confundirlos cuesta dinero o datos.
 *
 * - **encontrado** → hay puesto que escribir.
 * - **no encontrado** → la Registraduría no tiene esa cédula. **No es un
 *   fallo**: reintentarlo es pagar 2Captcha por volver a oír que no existe.
 * - **fallo** → el servicio no respondió o respondió mal. Aquí sí se reintenta.
 *
 * El `motivo` del fallo es para el log, y por eso es un código corto y no un
 * mensaje: nunca lleva la cédula (Art. VII).
 */
final class ResultadoConsulta
{
    private const ENCONTRADO = 'encontrado';

    private const NO_ENCONTRADO = 'no_encontrado';

    private const FALLO = 'fallo';

    /**
     * @param  array<string, mixed>  $datos  ya traducido a nombres de columna de `voters`
     */
    private function __construct(
        private readonly string $estado,
        public readonly array $datos = [],
        public readonly ?string $via = null,
        public readonly ?string $motivo = null,
    ) {}

    /**
     * @param  array<string, mixed>  $datos
     */
    public static function encontrado(array $datos, ?string $via = null): self
    {
        return new self(self::ENCONTRADO, $datos, $via);
    }

    public static function noEncontrado(?string $via = null): self
    {
        return new self(self::NO_ENCONTRADO, via: $via);
    }

    public static function fallo(string $motivo): self
    {
        return new self(self::FALLO, motivo: $motivo);
    }

    public function esEncontrado(): bool
    {
        return $this->estado === self::ENCONTRADO;
    }

    public function esNoEncontrado(): bool
    {
        return $this->estado === self::NO_ENCONTRADO;
    }

    public function esFallo(): bool
    {
        return $this->estado === self::FALLO;
    }
}
