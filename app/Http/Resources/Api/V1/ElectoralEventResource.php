<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una elección con su candidato propio (Spec 0062 · Parte 0).
 *
 * El catálogo del tarjetón viaja **con** la elección y no en un endpoint aparte:
 * la pantalla de configuración lo necesita siempre —es de donde se elige el
 * número— y pedirlo en dos viajes solo abriría la ventana para mostrar un
 * selector vacío mientras llega el segundo.
 *
 * `tiene_actas` es lo que le dice al frontend si el número se escribe a mano o se
 * elige de una lista, que es la misma regla que valida el servidor.
 */
class ElectoralEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'tipo' => $this->tipo,
            'fecha' => $this->fecha?->toDateString(),

            'candidato_propio' => [
                'numero' => $this->candidato_propio_numero,
                'nombre' => $this->candidato_propio_nombre,
                'agrupacion' => $this->candidato_propio_agrupacion,
            ],

            'tiene_actas' => $this->actas_count !== null
                ? $this->actas_count > 0
                : $this->actas()->exists(),
            'actas' => $this->actas_count,

            'candidatos' => $this->whenLoaded('candidates', fn () => $this->candidates
                ->sortBy('numero')
                ->values()
                ->map(fn ($candidato) => [
                    'numero' => $candidato->numero,
                    'nombre' => $candidato->nombre,
                    'agrupacion' => $candidato->agrupacion,
                    'cargo' => $candidato->cargo,
                ])
                ->all()
            ),

            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
