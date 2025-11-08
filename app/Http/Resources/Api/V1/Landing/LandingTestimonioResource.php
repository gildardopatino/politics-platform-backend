<?php

namespace App\Http\Resources\Api\V1\Landing;

use App\Services\WasabiStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingTestimonioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Generate image URL based on storage disk
        $fotoUrl = null;
        if ($this->foto) {
            $disk = config('filesystems.default');
            
            if ($disk === 's3') {
                $wasabi = app(WasabiStorageService::class);
                $fotoUrl = $wasabi->getSignedUrl($this->foto, $this->tenant);
            } else {
                $fotoUrl = asset('storage/' . $this->foto);
            }
        }

        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'ocupacion' => $this->ocupacion,
            'municipio' => $this->municipio,
            'testimonio' => $this->testimonio,
            'foto' => $fotoUrl,
            'calificacion' => $this->calificacion,
            'isActive' => $this->is_active,
        ];
    }
}
