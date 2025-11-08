<?php

namespace App\Http\Resources\Api\V1\Landing;

use App\Services\WasabiStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingGaleriaResource extends JsonResource
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
            'descripcion' => $this->descripcion,
            'imagen' => $imageUrl,
            'categoria' => $this->categoria,
            'order' => $this->order,
            'isActive' => $this->is_active,
        ];
    }
}
