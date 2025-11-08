<?php

namespace App\Http\Resources\Api\V1\Landing;

use App\Services\WasabiStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BiografiaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $biografia = $this->biografia_data ?? [];

        // If imagen exists and is a storage key, generate URL
        // $this is the Tenant itself in this resource
        if (isset($biografia['imagen']) && !filter_var($biografia['imagen'], FILTER_VALIDATE_URL)) {
            $disk = config('filesystems.default');
            
            if ($disk === 's3') {
                $wasabi = app(WasabiStorageService::class);
                $biografia['imagen'] = $wasabi->getSignedUrl($biografia['imagen'], $this->resource);
            } else {
                $biografia['imagen'] = asset('storage/' . $biografia['imagen']);
            }
        }

        return $biografia;
    }
}
