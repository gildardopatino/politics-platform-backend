<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingResource extends JsonResource
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
            'titulo' => $this->titulo,
            'descripcion' => $this->descripcion,
            'fecha_programada' => $this->fecha_programada?->toISOString(),
            'fecha_realizacion' => $this->fecha_realizacion?->toISOString(),
            'direccion' => $this->direccion,
            'department' => $this->whenLoaded('department', fn() => new GeographyResource($this->department)),
            'city' => $this->whenLoaded('city', fn() => new GeographyResource($this->city)),
            'commune' => $this->whenLoaded('commune', fn() => new GeographyResource($this->commune)),
            'barrio' => $this->whenLoaded('barrio', fn() => new GeographyResource($this->barrio)),
            'latitud' => $this->latitud,
            'longitud' => $this->longitud,
            'status' => $this->status,
            'qr_code' => $this->qr_code,
            'planned_by' => $this->whenLoaded('plannedBy', fn() => new UserResource($this->plannedBy)),
            'template' => $this->whenLoaded('template', fn() => new MeetingTemplateResource($this->template)),
            'attendees_count' => $this->whenCounted('attendees'),
            'commitments_count' => $this->whenCounted('commitments'),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
