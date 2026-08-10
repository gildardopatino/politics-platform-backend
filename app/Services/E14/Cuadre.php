<?php

namespace App\Services\E14;

use App\Models\E14Acta;

/**
 * Veredicto del cuadre de un acta (Spec 0061).
 */
class Cuadre
{
    public function __construct(
        public readonly bool $cuadra,
        public readonly string $estado,
        public readonly string $motivo,
        public readonly int $sumaCalculada,
        public readonly int $sumaDeclarada,
        public readonly int $votosUrna,
        public readonly int $difNivelacion,
    ) {}

    public function esProcesada(): bool
    {
        return $this->estado === E14Acta::ESTADO_PROCESADA;
    }
}
