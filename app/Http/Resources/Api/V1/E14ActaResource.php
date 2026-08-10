<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un acta tal como la ve el cliente (Spec 0061 · Parte A).
 *
 * Las tres cifras del cuadre viajan siempre juntas, y el `estado` va con su
 * `observacion`: un acta rechazada sin el motivo obliga a rehacer la cuenta a
 * mano para saber qué le pasa.
 */
class E14ActaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'electoral_event_id' => $this->electoral_event_id,
            'evento' => $this->whenLoaded('electoralEvent', fn () => [
                'id' => $this->electoralEvent->id,
                'nombre' => $this->electoralEvent->nombre,
                'tipo' => $this->electoralEvent->tipo,
                'fecha' => $this->electoralEvent->fecha?->toDateString(),
            ]),
            'tipo' => $this->tipo,

            'departamento_code' => $this->departamento_code,
            'municipio_code' => $this->municipio_code,
            'zona' => $this->zona,
            'puesto' => $this->puesto,
            'mesa' => $this->mesa,
            'lugar' => $this->lugar,

            'archivo_nombre' => $this->archivo_nombre,
            'archivo_hash' => $this->archivo_hash,

            'estado' => $this->estado,
            'observacion' => $this->observacion,
            'fuente' => $this->fuente,
            'confianza' => $this->confianza,

            'suma_calculada' => $this->suma_calculada,
            'suma_declarada' => $this->suma_declarada,
            'votos_urna' => $this->votos_urna,
            'votantes_e11' => $this->votantes_e11,
            'dif_nivelacion' => $this->dif_nivelacion,

            'votos_blanco' => $this->votos_blanco,
            'votos_nulos' => $this->votos_nulos,
            'votos_no_marcados' => $this->votos_no_marcados,

            'resultados' => $this->whenLoaded('resultados', fn () => $this->resultados
                ->sortBy('numero')
                ->values()
                ->map(fn ($resultado) => [
                    'numero' => $resultado->numero,
                    'nombre' => $resultado->nombre,
                    'votos' => $resultado->votos,
                    'e14_candidate_id' => $resultado->e14_candidate_id,
                ])
                ->all()
            ),

            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
