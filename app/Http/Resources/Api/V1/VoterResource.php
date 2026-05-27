<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VoterResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'cedula' => $this->cedula,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'full_name' => $this->full_name,
            'email' => $this->email,
            'telefono' => $this->telefono,
            'direccion' => $this->direccion,

            // Ubicación geográfica
            'location_type' => $this->location_type,
            'barrio_id' => $this->barrio_id,
            'corregimiento_id' => $this->corregimiento_id,
            'vereda_id' => $this->vereda_id,
            'barrio' => $this->whenLoaded('barrio'),
            'corregimiento' => $this->whenLoaded('corregimiento'),
            'vereda' => $this->whenLoaded('vereda'),

            // Clasificación
            'tipo_votante_id' => $this->tipo_votante_id,
            'tipo_votante' => $this->whenLoaded('tipoVotante'),

            // Vínculo con reunión
            'meeting_id' => $this->meeting_id,
            'meeting' => $this->whenLoaded('meeting'),

            // Información electoral (registraduría)
            'departamento_votacion' => $this->departamento_votacion,
            'municipio_votacion' => $this->municipio_votacion,
            'puesto_votacion' => $this->puesto_votacion,
            'direccion_votacion' => $this->direccion_votacion,
            'mesa_votacion' => $this->mesa_votacion,
            'voting_place_id' => $this->voting_place_id,

            'has_multiple_records' => $this->has_multiple_records,

            // Relaciones opcionales
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('createdBy'),
            'calls' => $this->whenLoaded('calls'),

            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
