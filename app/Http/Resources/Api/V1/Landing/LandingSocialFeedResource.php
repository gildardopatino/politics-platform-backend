<?php

namespace App\Http\Resources\Api\V1\Landing;

use App\Services\WasabiStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingSocialFeedResource extends JsonResource
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
            'plataforma' => $this->plataforma,
            'usuario' => $this->usuario,
            'contenido' => $this->contenido,
            'fecha' => $this->fecha->toISOString(),
            'likes' => $this->likes,
            'compartidos' => $this->compartidos,
            'comentarios' => $this->comentarios,
            'imagen' => $imageUrl,
            'isActive' => $this->is_active,
        ];
    }
}
