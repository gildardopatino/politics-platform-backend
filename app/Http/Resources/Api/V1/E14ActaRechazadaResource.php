<?php

namespace App\Http\Resources\Api\V1;

use App\Models\E14Acta;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un acta rechazada tal como la ve el panel (Spec 0093 · RF-B6).
 *
 * Las dos elecciones viajan en los dos idiomas: el enum, para que el cliente
 * pueda agrupar o filtrar, y el nombre en español, para que la tabla se pinte
 * sin tener que llevar su propia traducción (Art. IX). El `motivo` ya viene
 * redactado: es la frase que explica el renglón.
 */
class E14ActaRechazadaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'electoral_event_id' => $this->electoral_event_id,

            'archivo_nombre' => $this->archivo_nombre,
            // El hash es lo que identifica al PDF: con él, quien vuelva a
            // subirlo sabe que es el mismo archivo que ya se rechazó.
            'archivo_hash' => $this->archivo_hash,

            'eleccion_detectada' => $this->eleccion_detectada,
            'eleccion_detectada_nombre' => $this->eleccion_detectada
                ? E14Acta::nombreDe($this->eleccion_detectada)
                : null,
            'eleccion_esperada' => $this->eleccion_esperada,
            'eleccion_esperada_nombre' => $this->eleccion_esperada
                ? E14Acta::nombreDe($this->eleccion_esperada)
                : null,

            'motivo' => $this->motivo,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
