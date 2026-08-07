<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Vista pública de una reunión (Spec 0021, hallazgos F3 y F6).
 *
 * La usan las dos rutas del QR, que van **sin autenticación**: quien tenga el
 * código ve esto. De ahí las dos reglas:
 *
 * 1. **Nada interno ni PII.** Fuera `tenant_id`, `metadata`, el `qr_code`, los
 *    ids de usuario y el correo o teléfono de quien organiza. Del planner solo
 *    queda el nombre, que es lo que identifica el encuentro ante quien asiste.
 *    Antes `showByQR` devolvía el `MeetingResource` completo.
 * 2. **Las claves siguen en español** (`titulo`, `descripcion`,
 *    `lugar_direccion`) porque son las que pinta `MeetingCheckIn.tsx`. Lo que se
 *    corrigió es de dónde salen: el controller las leía del modelo con esos
 *    mismos nombres, donde en realidad son `title`, `description` y `direccion`,
 *    así que llegaban siempre null.
 *
 * @mixin \App\Models\Meeting
 */
class PublicMeetingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->title,
            'descripcion' => $this->description,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'status' => $this->status,

            'lugar_nombre' => $this->lugar_nombre,
            'lugar_direccion' => $this->direccion,

            'planner' => [
                'name' => $this->planner?->name,
            ],

            'location' => [
                'department' => $this->department?->nombre,
                'municipality' => $this->municipality?->nombre,
                'commune' => $this->commune?->nombre,
                'barrio' => $this->barrio?->nombre,
            ],

            // Preguntas configurables del formulario público.
            'template' => $this->template ? [
                'id' => $this->template->id,
                'nombre' => $this->template->name,
                'descripcion' => $this->template->description,
                'fields' => $this->template->fields,
            ] : null,

            'attendees_count' => $this->attendees()->count(),
            'checked_in_count' => $this->attendees()->where('checked_in', true)->count(),
        ];
    }
}
