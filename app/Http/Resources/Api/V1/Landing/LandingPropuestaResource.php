<?php

namespace App\Http\Resources\Api\V1\Landing;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingPropuestaResource extends JsonResource
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
            'categoria' => $this->categoria,
            'titulo' => $this->titulo,
            'descripcion' => $this->descripcion,
            'puntosClave' => $this->puntos_clave,
            'icono' => $this->icono,
            'order' => $this->order,
            'isActive' => $this->is_active,
        ];
    }
}
