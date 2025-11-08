<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Generar QR base64 si existe el código
        $qrData = null;
        if ($this->qr_code) {
            // Si viene del create, usar los datos adjuntos
            if (isset($this->qr_data)) {
                $qrData = $this->qr_data;
            } else {
                // Generar bajo demanda
                $qrCodeService = app(\App\Services\QRCodeService::class);
                $qrData = $qrCodeService->getQRCodeBase64($this->qr_code);
                $qrData['code'] = $this->qr_code;
            }
        }

        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'title' => $this->title,
            'description' => $this->description,
            'starts_at' => $this->starts_at?->toISOString(),
            'ends_at' => $this->ends_at?->toISOString(),
            'lugar_nombre' => $this->lugar_nombre,
            'direccion' => $this->direccion,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'qr_code' => $this->qr_code,
            'qr_data' => $qrData,
            'status' => $this->status,
            'metadata' => $this->metadata,
            
            // Relaciones con usuarios
            'planner_user_id' => $this->planner_user_id,
            'planner' => $this->whenLoaded('planner', fn() => new UserResource($this->planner)),
            
            // Relaciones geográficas
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn() => new GeographyResource($this->department)),
            
            'municipality_id' => $this->municipality_id,
            'municipality' => $this->whenLoaded('municipality', fn() => new GeographyResource($this->municipality)),
            
            'commune_id' => $this->commune_id,
            'commune' => $this->whenLoaded('commune', fn() => new GeographyResource($this->commune)),
            
            'barrio_id' => $this->barrio_id,
            'barrio' => $this->whenLoaded('barrio', fn() => new GeographyResource($this->barrio)),
            
            'corregimiento_id' => $this->corregimiento_id,
            'corregimiento' => $this->whenLoaded('corregimiento', fn() => new GeographyResource($this->corregimiento)),
            
            'vereda_id' => $this->vereda_id,
            'vereda' => $this->whenLoaded('vereda', fn() => new GeographyResource($this->vereda)),
            
            // Relación con template
            'template_id' => $this->template_id,
            'template' => $this->whenLoaded('template', fn() => new MeetingTemplateResource($this->template)),
            
            // Colecciones relacionadas
            'attendees' => $this->whenLoaded('attendees', fn() => MeetingAttendeeResource::collection($this->attendees)),
            'attendees_count' => $this->whenCounted('attendees'),
            
            'commitments' => $this->whenLoaded('commitments', fn() => CommitmentResource::collection($this->commitments)),
            'commitments_count' => $this->whenCounted('commitments'),
            
            // Recordatorio activo
            'active_reminder' => $this->whenLoaded('activeReminder', fn() => new MeetingReminderResource($this->activeReminder)),
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'deleted_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
