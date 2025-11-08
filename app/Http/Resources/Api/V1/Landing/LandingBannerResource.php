<?php

namespace App\Http\Resources\Api\V1\Landing;

use App\Services\WasabiStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LandingBannerResource extends JsonResource
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
        if ($this->image) {
            $disk = config('filesystems.default');
            
            if ($disk === 's3') {
                $wasabi = app(WasabiStorageService::class);
                $imageUrl = $wasabi->getSignedUrl($this->image, $this->tenant);
            } else {
                // Local storage
                $imageUrl = asset('storage/' . $this->image);
            }
        }

        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'description' => $this->description,
            'image' => $imageUrl,
            'ctaText' => $this->cta_text,
            'ctaLink' => $this->cta_link,
            'order' => $this->order,
            'isActive' => $this->is_active,
        ];
    }
}
