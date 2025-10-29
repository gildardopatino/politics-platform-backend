<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'email' => $this->email,
            'telefono' => $this->telefono,
            'is_super_admin' => $this->is_super_admin,
            'is_team_leader' => $this->is_team_leader,
            'reports_to' => $this->reports_to,
            'supervisor' => $this->whenLoaded('supervisor', fn() => new UserResource($this->supervisor)),
            'tenant' => $this->whenLoaded('tenant', fn() => new TenantResource($this->tenant)),
            'roles' => $this->whenLoaded('roles', fn() => $this->roles->pluck('name')),
            'permissions' => $this->whenLoaded('permissions', fn() => $this->permissions->pluck('name')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
