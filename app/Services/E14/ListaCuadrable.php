<?php

namespace App\Services\E14;

/**
 * Lo que el cuadre necesita saber de una agrupación (Spec 0067 · Parte B).
 *
 * Es un DTO y no el modelo `E14ListaResultado` a propósito: `CuadreService` se
 * prueba sin base de datos —es la regla que decide qué votos entran al
 * consolidado— y tiene que poder evaluar tanto una lista que ya está guardada
 * como una que acaba de llegar en el payload del worker y todavía no existe.
 *
 * Los preferentes llegan **ya sumados**: el self-check compara totales, no
 * necesita el detalle, y pasar la suma evita que el servicio tenga que saber si
 * la colección viene de Eloquent o de un array del request.
 */
class ListaCuadrable
{
    public function __construct(
        public readonly int $numero,
        public readonly ?string $nombre,
        public readonly int $votosSoloLista,
        public readonly int $sumaPreferentes,
        public readonly int $totalDeclarado,
    ) {}

    public function sumaCalculada(): int
    {
        return $this->votosSoloLista + $this->sumaPreferentes;
    }

    public function cuadra(): bool
    {
        return $this->sumaCalculada() === $this->totalDeclarado;
    }

    /**
     * Cómo se nombra la lista en un motivo de revisión.
     *
     * Con diecisiete agrupaciones por acta, «no cuadra por un voto» no le sirve
     * a quien tiene el papel delante; el número y el partido le señalan la hoja.
     */
    public function etiqueta(): string
    {
        return filled($this->nombre) ? "{$this->numero} · {$this->nombre}" : (string) $this->numero;
    }

    /**
     * La lista tal como llega en el payload del worker (Spec 0067 · Parte A).
     *
     * @param  array<string, mixed>  $fila
     */
    public static function desdePayload(array $fila): self
    {
        $preferentes = $fila['preferentes'] ?? [];

        return new self(
            numero: (int) $fila['lista_numero'],
            nombre: $fila['lista_nombre'] ?? null,
            votosSoloLista: (int) ($fila['votos_solo_lista'] ?? 0),
            sumaPreferentes: (int) array_sum(array_column($preferentes, 'votos')),
            totalDeclarado: (int) ($fila['total_agrupacion'] ?? 0),
        );
    }
}
