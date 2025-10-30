<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingAttendeeResource extends JsonResource
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
            'meeting_id' => $this->meeting_id,
            'cedula' => $this->cedula,
            'nombres' => $this->nombres,
            'apellidos' => $this->apellidos,
            'full_name' => $this->full_name,
            'telefono' => $this->telefono,
            'email' => $this->email,
            'extra_fields' => $this->extra_fields,
            'checked_in' => $this->checked_in,
            'checked_in_at' => $this->checked_in_at?->toISOString(),
            
            // Relación con reunión
            'meeting' => $this->whenLoaded('meeting', fn() => new MeetingResource($this->meeting)),
            
            // Relación con usuario creador
            'created_by' => $this->created_by,
            'creator' => $this->whenLoaded('createdBy', fn() => new UserResource($this->createdBy)),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
