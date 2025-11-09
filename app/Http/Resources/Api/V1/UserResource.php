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
            'phone' => $this->phone,
            'cedula' => $this->cedula,
            'is_super_admin' => $this->is_super_admin,
            'is_team_leader' => $this->is_team_leader,
            'reports_to' => $this->reports_to,
            
            // OLD: Single geographic IDs (backward compatibility)
            'department_id' => $this->department_id,
            'municipality_id' => $this->municipality_id,
            'commune_id' => $this->commune_id,
            'barrio_id' => $this->barrio_id,
            'corregimiento_id' => $this->corregimiento_id,
            'vereda_id' => $this->vereda_id,
            
            // Relationships
            'supervisor' => $this->whenLoaded('supervisor', fn() => new UserResource($this->supervisor)),
            'tenant' => $this->whenLoaded('tenant', fn() => new TenantResource($this->tenant)),
            
            // NEW: Multiple geographic assignments (arrays)
            'departments' => $this->whenLoaded('departments', function() {
                return $this->departments->map(fn($dept) => [
                    'id' => $dept->id,
                    'name' => $dept->nombre,
                    'codigo' => $dept->codigo,
                ])->values();
            }),
            'municipalities' => $this->whenLoaded('municipalities', function() {
                return $this->municipalities->map(fn($muni) => [
                    'id' => $muni->id,
                    'name' => $muni->nombre,
                    'codigo' => $muni->codigo,
                ])->values();
            }),
            'communes' => $this->whenLoaded('communes', function() {
                return $this->communes->map(fn($commune) => [
                    'id' => $commune->id,
                    'name' => $commune->nombre,
                    'codigo' => $commune->codigo,
                ])->values();
            }),
            'barrios' => $this->whenLoaded('barrios', function() {
                return $this->barrios->map(fn($barrio) => [
                    'id' => $barrio->id,
                    'name' => $barrio->nombre,
                    'codigo' => $barrio->codigo,
                ])->values();
            }),
            'corregimientos' => $this->whenLoaded('corregimientos', function() {
                return $this->corregimientos->map(fn($corre) => [
                    'id' => $corre->id,
                    'name' => $corre->nombre,
                    'codigo' => $corre->codigo,
                ])->values();
            }),
            'veredas' => $this->whenLoaded('veredas', function() {
                return $this->veredas->map(fn($vereda) => [
                    'id' => $vereda->id,
                    'name' => $vereda->nombre,
                    'codigo' => $vereda->codigo,
                ])->values();
            }),
            
            // OLD: Single geographic relationships (deprecated, for backward compatibility)
            'department' => $this->whenLoaded('department', function() {
                return $this->department ? [
                    'id' => $this->department->id,
                    'name' => $this->department->nombre,
                    'codigo' => $this->department->codigo,
                ] : null;
            }),
            'municipality' => $this->whenLoaded('municipality', function() {
                return $this->municipality ? [
                    'id' => $this->municipality->id,
                    'name' => $this->municipality->nombre,
                    'codigo' => $this->municipality->codigo,
                ] : null;
            }),
            'commune' => $this->whenLoaded('commune', function() {
                return $this->commune ? [
                    'id' => $this->commune->id,
                    'name' => $this->commune->nombre,
                    'codigo' => $this->commune->codigo,
                ] : null;
            }),
            'barrio' => $this->whenLoaded('barrio', function() {
                return $this->barrio ? [
                    'id' => $this->barrio->id,
                    'name' => $this->barrio->nombre,
                    'codigo' => $this->barrio->codigo,
                ] : null;
            }),
            'corregimiento' => $this->whenLoaded('corregimiento', function() {
                return $this->corregimiento ? [
                    'id' => $this->corregimiento->id,
                    'name' => $this->corregimiento->nombre,
                    'codigo' => $this->corregimiento->codigo,
                ] : null;
            }),
            'vereda' => $this->whenLoaded('vereda', function() {
                return $this->vereda ? [
                    'id' => $this->vereda->id,
                    'name' => $this->vereda->nombre,
                    'codigo' => $this->vereda->codigo,
                ] : null;
            }),
            
            'roles' => $this->whenLoaded('roles', function() {
                return $this->roles->pluck('name');
            }, []),
            'permissions' => $this->whenLoaded('roles', function() {
                // Obtener todos los permisos del usuario (directos + de roles)
                return $this->getAllPermissions()->pluck('name')->unique()->values();
            }, []),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
