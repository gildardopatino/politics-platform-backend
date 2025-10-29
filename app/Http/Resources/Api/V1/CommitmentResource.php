<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommitmentResource extends JsonResource
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
            'descripcion' => $this->descripcion,
            'fecha_compromiso' => $this->fecha_compromiso?->toDateString(),
            'fecha_cumplimiento' => $this->fecha_cumplimiento?->toDateString(),
            'status' => $this->status,
            'notas' => $this->notas,
            'meeting' => $this->whenLoaded('meeting', fn() => new MeetingResource($this->meeting)),
            'assigned_user' => $this->whenLoaded('assignedUser', fn() => new UserResource($this->assignedUser)),
            'priority' => $this->whenLoaded('priority', fn() => [
                'id' => $this->priority->id,
                'name' => $this->priority->name,
                'color' => $this->priority->color,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
