<?php

namespace App\Services\Registraduria;

/**
 * Lo que devuelve el servicio de Registraduría, ya clasificado (Spec 0091).
 *
 * Tres desenlaces, y la diferencia importa para la cola: `no_encontrado` **no**
 * es un fallo —la Registraduría respondió y esa cédula no está en el censo—, así
 * que no tiene sentido reintentarlo; un fallo (servicio caído, reto no superado)
 * sí se reintenta.
 */
final class ResultadoConsulta
{
    public const ENCONTRADO = 'encontrado';

    public const NO_ENCONTRADO = 'no_encontrado';

    public const FALLO = 'fallo';

    /**
     * @param  array<int, array<string, mixed>>  $datos
     */
    private function __construct(
        public readonly string $estado,
        public readonly ?string $via = null,
        public readonly array $datos = [],
        public readonly ?string $motivo = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $datos
     */
    public static function encontrado(array $datos, ?string $via = null): self
    {
        return new self(self::ENCONTRADO, $via, $datos);
    }

    public static function noEncontrado(?string $via = null): self
    {
        return new self(self::NO_ENCONTRADO, $via);
    }

    /**
     * El motivo es para el log: llega ya saneado de PII.
     */
    public static function fallo(string $motivo): self
    {
        return new self(self::FALLO, null, [], $motivo);
    }

    public function esEncontrado(): bool
    {
        return $this->estado === self::ENCONTRADO;
    }

    public function esNoEncontrado(): bool
    {
        return $this->estado === self::NO_ENCONTRADO;
    }

    public function haFallado(): bool
    {
        return $this->estado === self::FALLO;
    }

    /**
     * La primera fila del resultado: una cédula vota en un solo puesto.
     *
     * @return array<string, mixed>|null
     */
    public function primerRegistro(): ?array
    {
        $primero = $this->datos[0] ?? null;

        return is_array($primero) && $primero !== [] ? $primero : null;
    }
}
