<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OccupationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'activo' => $this->activo,
            // Presente solo cuando el oficio viaja dentro de un perfil.
            'relacion' => $this->whenPivotLoaded('voter_occupations', fn () => $this->pivot->relacion),
            'alias' => AliasResource::collection($this->whenLoaded('aliases')),
        ];
    }
}
