<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
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
            'slug' => $this->slug,
            'nombre' => $this->nombre,
            'tipo_cargo' => $this->tipo_cargo,
            'identificacion' => $this->identificacion,
            'metadata' => $this->metadata,
            'users_count' => $this->whenCounted('users'),
            'meetings_count' => $this->whenCounted('meetings'),
            'campaigns_count' => $this->whenCounted('campaigns'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
