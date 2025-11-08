<?php

namespace App\Http\Resources\Api\V1\Landing;

use App\Services\WasabiStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingEventoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Generate image URL based on storage disk
        $imageUrl = null;
        if ($this->imagen) {
            $disk = config('filesystems.default');
            
            if ($disk === 's3') {
                $wasabi = app(WasabiStorageService::class);
                $imageUrl = $wasabi->getSignedUrl($this->imagen, $this->tenant);
            } else {
                $imageUrl = asset('storage/' . $this->imagen);
            }
        }

        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'fecha' => $this->fecha->format('Y-m-d'),
            'hora' => $this->hora,
            'lugar' => $this->lugar,
            'descripcion' => $this->descripcion,
            'imagen' => $imageUrl,
            'tipo' => $this->tipo,
            'isActive' => $this->is_active,
        ];
    }
}
