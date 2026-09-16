<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoterProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'voter_id' => $this->voter_id,
            'busca_empleo' => $this->busca_empleo,
            'disponibilidad' => $this->disponibilidad,
            'nivel_educativo' => $this->nivel_educativo,
            'anios_experiencia' => $this->anios_experiencia,
            'notas' => $this->notas,
            'autoriza_tratamiento_datos' => $this->autoriza_tratamiento_datos,
            'autorizado_at' => $this->autorizado_at?->toISOString(),
            'oficios' => OccupationResource::collection($this->whenLoaded('occupations')),

            // Solo lo que la bolsa necesita para llamar a la persona; el resto del
            // votante se pide por su propio endpoint (Art. VII).
            'votante' => $this->whenLoaded('voter', fn () => [
                'id' => $this->voter->id,
                'cedula' => $this->voter->cedula,
                'nombre_completo' => $this->voter->full_name,
                'telefono' => $this->voter->telefono,
            ]),

            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
