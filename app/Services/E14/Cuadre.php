<?php

namespace App\Services\E14;

use App\Models\E14Acta;

/**
 * Veredicto del cuadre de un acta (Spec 0061).
 */
class Cuadre
{
    /**
     * @param  array<int, string>  $listasDescuadradas  Corporación (Spec 0067):
     *                                                  qué agrupaciones fallaron su self-check. Va aparte del motivo
     *                                                  para que el panel pueda resaltar esas hojas sin tener que leer
     *                                                  una cadena. En uninominal siempre está vacío.
     */
    public function __construct(
        public readonly bool $cuadra,
        public readonly string $estado,
        public readonly string $motivo,
        public readonly int $sumaCalculada,
        public readonly int $sumaDeclarada,
        public readonly int $votosUrna,
        public readonly int $difNivelacion,
        public readonly array $listasDescuadradas = [],
    ) {}

    public function esProcesada(): bool
    {
        return $this->estado === E14Acta::ESTADO_PROCESADA;
    }
}
