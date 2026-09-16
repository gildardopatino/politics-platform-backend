<?php

namespace App\Http\Resources\Api\V1;

use App\Services\VoterResumeService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Una hoja de vida en las respuestas (Spec 0096).
 *
 * Sin `archivo_key`: la ruta en disco no sale de aquí (Art. VII). Lo que se
 * entrega es un enlace firmado que caduca en minutos.
 */
class VoterResumeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'voter_id' => $this->voter_id,
            'nombre_original' => $this->nombre_original,
            'mime' => $this->mime,
            'tamano_bytes' => $this->tamano_bytes,
            'url_descarga' => app(VoterResumeService::class)->urlFirmada($this->resource),
            'subido_por' => $this->subido_por,
            'subido_por_nombre' => $this->whenLoaded('subidoPor', fn () => $this->subidoPor?->name),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
